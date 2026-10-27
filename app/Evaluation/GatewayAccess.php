<?php

declare(strict_types=1);

namespace App\Evaluation;

use App\Models\User;
use App\Support\Rewards\RewardClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Existing web identity, with a short diagnostic authorization window. No new balance/auth store. */
final class GatewayAccess
{
    public static function enabled(): bool
    {
        return config('evaluation_gateway.enabled') === true && app()->environment(['local', 'testing']);
    }

    public static function stamp(User $user): array
    {
        return ['user_id' => (int) $user->id, 'password_version' => hash('sha256', $user->getAuthPassword()),
            'expires_at' => RewardClock::now()->addMinutes(30)->getTimestamp()];
    }

    public static function run(Request $request, callable $action): mixed
    {
        abort_unless(self::enabled(), 404);
        $id = Auth::guard('web')->id();
        $stamp = $request->session()->get('nl_gateway_auth');
        abort_unless($id && is_array($stamp), 401, 'Sesi evaluasi tidak tersedia.');
        return DB::transaction(function () use ($request, $id, $stamp, $action) {
            // Same account-first lock order as portal, staff, shop and battle settlement.
            $actor = User::whereKey($id)->lockForUpdate()->first();
            abort_unless($actor && in_array((int) $actor->account_type, [0, 1], true)
                && ($stamp['user_id'] ?? null) === (int) $actor->id
                && is_int($stamp['expires_at'] ?? null) && $stamp['expires_at'] > RewardClock::now()->getTimestamp()
                && is_string($stamp['password_version'] ?? null)
                && hash_equals(hash('sha256', $actor->getAuthPassword()), $stamp['password_version']),
                401, 'Sesi evaluasi kedaluwarsa atau akses berubah.');
            return $action($actor);
        }, 3);
    }
}
