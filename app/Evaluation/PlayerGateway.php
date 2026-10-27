<?php

declare(strict_types=1);

namespace App\Evaluation;

use App\Models\User;
use App\Services\Rewards\ClientStateService;
use App\Services\Rewards\RewardService;
use App\Services\Shop\ShopPurchase;
use App\Support\Rewards\RewardClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/** Explicit JSON diagnostic operations. Not an AMF bridge, generic RPC or admin execution API. */
final class PlayerGateway
{
    public function bootstrap(Request $request): JsonResponse
    {
        return response()->json(['protocol' => 'evaluation-v1', 'csrf_token' => $request->session()->token(), 'gameplay_available' => false]);
    }

    public function login(Request $request): JsonResponse
    {
        $input = $this->input($request, ['username' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'max:255']]);
        // Independent IP and normalized-identity buckets. No username or password is logged.
        foreach ([['ip:'.hash('sha256', (string) $request->ip()), 10], ['user:'.hash('sha256', mb_strtolower(trim($input['username']))), 5]] as [$key, $limit]) {
            $key = 'nl-gateway-login:'.$key;
            abort_if(RateLimiter::tooManyAttempts($key, $limit), 429, 'Terlalu banyak percobaan login.', ['Retry-After' => (string) max(1, RateLimiter::availableIn($key))]);
            RateLimiter::hit($key, 60);
        }
        $guard = Auth::guard('web');
        abort_unless($guard->attempt(['username' => $input['username'], 'password' => $input['password'], 'account_type' => [0, 1]], false), 401, 'Kredensial tidak valid.');
        $user = $guard->user()->fresh();
        if (!$user || !in_array((int) $user->account_type, [0, 1], true) || !hash_equals($guard->user()->getAuthPassword(), $user->getAuthPassword())) {
            $guard->logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
            abort(401, 'Kredensial tidak valid.');
        }
        $request->session()->regenerate();
        $stamp = GatewayAccess::stamp($user);
        $request->session()->put('nl_gateway_auth', $stamp);
        return response()->json(['user_id' => (int) $user->id, 'expires_at' => $stamp['expires_at'], 'csrf_token' => $request->session()->token()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->input($request, []);
        GatewayAccess::run($request, fn (User $user) => true);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['logged_out' => true]);
    }

    public function characters(Request $request): JsonResponse
    {
        return $this->read($request, fn (User $user) => $this->bounded(DB::table('characters')->where('user_id', $user->id)->orderBy('id')->limit(101)->get(['id', 'name', 'level', 'rank'])));
    }

    public function state(Request $request, string $character): JsonResponse
    {
        return $this->read($request, function (User $user) use ($character) {
            $state = app(ClientStateService::class)->snapshot($user, (int) $character);
            $row = DB::table('characters')->where('id', $state['character_id'])->first(['level', 'xp', 'rank']);
            return $state + ['level' => (int) $row->level, 'xp' => (int) $row->xp, 'rank' => (int) $row->rank];
        });
    }

    public function inventory(Request $request, string $character): JsonResponse
    {
        return $this->read($request, function (User $user) use ($character) {
            $state = app(ClientStateService::class)->snapshot($user, (int) $character);
            return ['character_id' => $state['character_id'], 'revision' => $state['revision'],
                'items' => $this->bounded(DB::table('character_items')->where('character_id', $character)->orderBy('id')->limit(101)->get(['item_id', 'quantity', 'category'])),
                'skills' => $this->bounded(DB::table('character_skills')->where('character_id', $character)->orderBy('id')->limit(101)->get(['skill_id'])),
                'pets' => $this->bounded(DB::table('character_pets')->where('character_id', $character)->orderBy('id')->limit(101)->get(['id', 'pet_id', 'level']))];
        });
    }

    public function offers(Request $request): JsonResponse
    {
        return $this->read($request, fn (User $user) => $this->bounded(DB::table('nl_shop_offers as o')->join('nl_catalog as c', 'c.id', '=', 'o.catalog_id')
            ->where('o.active', true)->where('c.enabled', true)->whereIn('c.rights_basis', ['self_authored', 'permission_documented'])->whereIn('c.kind', ['item', 'skill'])
            ->orderBy('o.id')->limit(101)->get(['o.id', 'o.title', 'o.price_tokens', 'o.price_gold', 'o.max_quantity', 'c.kind', 'c.content_key'])));
    }

    public function campaigns(Request $request): JsonResponse
    {
        return $this->read($request, function (User $user) {
            $now = RewardClock::now();
            $rows = DB::table('nl_campaigns as c')->where('c.kind', 'starter')->where('c.status', 'active')->where('c.starts_at', '<=', $now)->where('c.ends_at', '>', $now)
                ->whereColumn('c.claimed_count', '<', 'c.max_claims')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('nl_claims as r')->whereColumn('r.campaign_id', 'c.id')->where('r.user_id', $user->id))
                ->orderBy('c.id')->limit(101)->get(['c.id', 'c.title', 'c.choices']);
            foreach ($rows as $row) { $row->choices = json_decode($row->choices, true, 32, JSON_THROW_ON_ERROR); }
            return $this->bounded($rows);
        });
    }

    public function equipment(Request $request, string $character): JsonResponse
    {
        $input = $this->input($request, ['revision' => ['required', 'integer', 'min:0', 'max:4294967295'], 'equipment' => ['required', 'array']]);
        return $this->read($request, fn (User $user) => app(ClientStateService::class)->saveEquipment($user, (int) $character, $input));
    }

    public function starter(Request $request): JsonResponse
    {
        $input = $this->input($request, $this->claimRules() + ['campaign_id' => ['required', 'integer', 'min:1', 'max:2147483647']]);
        return $this->write($request, 'starter', fn (User $user) => app(RewardService::class)->starter($user, (int) $input['character_id'], (int) $input['campaign_id'], $input['choice'], $input['request_key']));
    }

    public function redeem(Request $request): JsonResponse
    {
        $input = $this->input($request, $this->claimRules() + ['code' => ['required', 'string', 'max:80']]);
        return $this->write($request, 'redeem', fn (User $user) => app(RewardService::class)->redeem($user, (int) $input['character_id'], $input['code'], $input['choice'], $input['request_key']));
    }

    public function purchase(Request $request): JsonResponse
    {
        $input = $this->input($request, ['character_id' => ['required', 'integer', 'min:1', 'max:2147483647'], 'offer_id' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'], 'request_key' => ['required', 'uuid']]);
        return $this->write($request, 'purchase', fn (User $user) => app(ShopPurchase::class)->buy($user, $input));
    }

    private function claimRules(): array
    {
        return ['character_id' => ['required', 'integer', 'min:1', 'max:2147483647'], 'choice' => ['required', 'string', 'regex:/\A[a-z0-9_-]{1,40}\z/D'], 'request_key' => ['required', 'uuid']];
    }

    private function input(Request $request, array $rules): array
    {
        $input = $request->json()->all();
        // Empty object allowed only for logout; input allowlist enforced without mass assignment.
        abort_if(array_diff(array_keys($input), array_keys($rules)) !== [], 422, 'Field tidak didukung.');
        return Validator::make($input, $rules)->validate();
    }

    private function read(Request $request, callable $action): JsonResponse
    {
        return response()->json(GatewayAccess::run($request, $action));
    }

    private function write(Request $request, string $kind, callable $action): JsonResponse
    {
        return $this->read($request, function (User $user) use ($action, $kind) {
            $key = 'nl-gateway-write:'.$user->id;
            abort_if(RateLimiter::tooManyAttempts($key, 60), 429, 'Terlalu banyak permintaan.', ['Retry-After' => '60']);
            RateLimiter::hit($key, 60);
            $result = $action($user);
            if (!$result['replayed']) {
                DB::table('nl_operations_audit')->insert(['actor_id' => $user->id, 'action' => 'gateway.'.$kind, 'subject' => 'claim:'.$result['id'],
                    'details' => json_encode(['adapter' => 'evaluation-json-v1'], JSON_THROW_ON_ERROR), 'created_at' => RewardClock::now()]);
            }
            return $result;
        });
    }

    private function bounded(\Illuminate\Support\Collection $rows): array
    {
        return ['data' => $rows->take(100)->values()->all(), 'limit' => 100, 'truncated' => $rows->count() > 100];
    }
}
