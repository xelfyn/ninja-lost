<?php

declare(strict_types=1);

namespace App\Evaluation;

use App\Content\SkillBlueprint;
use App\Models\User;
use App\Services\Content\PublishedLoadout;
use App\Services\Rewards\ClientStateService;
use App\Support\Economy\StateRevision;
use App\Support\Rewards\RewardClock;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Paused mirror training. Never produces a mission verdict or calls a balance/reward writer. */
final class TrainingService
{
    private function locked(User $user, callable $action): mixed
    {
        abort_unless(TrainingBoundary::enabled(), 404);
        return DB::transaction(function () use ($user, $action) {
            $actor = User::whereKey($user->id)->lockForUpdate()->first();
            abort_unless($actor && in_array((int) $actor->account_type, [0, 1], true), 403);
            return $action($actor);
        }, 3);
    }
    private function character(User $user, int $id): object
    {
        $character = DB::table('characters')->where('id', $id)->where('user_id', $user->id)->lockForUpdate()->first();
        abort_unless($character, 404); return $character;
    }
    private function validate(array $input, array $rules): array
    {
        $rootKeys = array_filter(array_keys($rules), fn ($key) => !str_contains($key, '.'));
        Validator::make(['d' => $input], ['d' => ['required', 'array:'.implode(',', $rootKeys)]]
            + array_combine(array_map(fn ($k) => 'd.'.$k, array_keys($rules)), array_values($rules)))->validate();
        return $input;
    }
    private function revisionRules(): array { return ['required', 'integer', 'between:0,4294967295']; }
    private function digest(array $value): string { return hash('sha256', SkillBlueprint::json($value)); }
    private function response(object $run): array
    {
        $status = $run->status;
        if ($status === 'active' && $run->expires_at <= RewardClock::now()->format('Y-m-d H:i:s')) $status = 'expired';
        return ['id' => $run->id, 'character_id' => (int) $run->character_id, 'revision' => (int) $run->revision,
            'status' => $status, 'expires_at' => $run->expires_at.'Z', 'state' => json_decode($run->state, true, 48, JSON_THROW_ON_ERROR)];
    }
    private function ownedRun(User $actor, string $id): object
    {
        $run = DB::table('nl_training_runs')->where('id', $id)->where('user_id', $actor->id)->lockForUpdate()->first();
        abort_unless($run, 404); return $run;
    }

    public function loadout(User $user, int $characterId): array
    {
        return $this->locked($user, function (User $actor) use ($characterId) {
            $c = $this->character($actor, $characterId);
            $state = app(ClientStateService::class)->snapshot($actor, $characterId);
            $items = DB::table('character_items as o')->join('items as i', 'i.item_id', '=', 'o.item_id')
                ->join('nl_studio_equipment as m', 'm.content_key', '=', 'o.item_id')->join('nl_catalog as c', 'c.id', '=', 'm.catalog_id')
                ->where('o.character_id', $characterId)->where('o.quantity', '>', 0)->where('c.enabled', true)->orderBy('o.id')->limit(101)
                ->get(['i.item_id as id', 'i.name', 'i.category as slot', 'i.level']);
            $skills = DB::table('character_skills as o')->join('skills as s', 's.skill_id', '=', 'o.skill_id')
                ->join('nl_catalog as c', function ($join) { $join->on('c.content_key', '=', 'o.skill_id')->where('c.kind', 'skill'); })
                ->where('o.character_id', $characterId)->where('c.enabled', true)->where('c.source_revision', 'like', 'nl-studio:%')
                ->orderBy('o.id')->limit(101)->get(['s.skill_id as id', 's.name', 's.level']);
            $equipment = array_map(fn ($key) => in_array($key, [null, '', 0, '0'], true) ? '' : $key, $state['equipment']);
            return ['character_id' => $characterId, 'name' => $c->name, 'revision' => $state['revision'], 'equipment' => $equipment,
                'skills' => $c->equipment_skills ? explode(',', $c->equipment_skills) : [],
                'items' => $items->take(100)->values()->all(), 'available_skills' => $skills->take(100)->values()->all(),
                'truncated' => $items->count() > 100 || $skills->count() > 100];
        });
    }

    public function saveLoadout(User $user, int $characterId, array $input): array
    {
        $this->validate($input, ['revision' => $this->revisionRules(), 'equipment' => ['required', 'array:weapon,back,clothing,accessory', 'min:1'],
            'skills' => ['present', 'array', 'max:8'], 'skills.*' => ['required', 'string', 'regex:/\Askill_nl_[a-z0-9_]{1,60}\z/D', 'distinct:strict']]);
        abort_unless(array_is_list($input['skills']), 422);
        return $this->locked($user, function (User $actor) use ($characterId, $input) {
            $this->character($actor, $characterId);
            abort_unless((int) $input['revision'] === StateRevision::current((int) $actor->id), 409, 'Loadout usang; muat ulang.');
            // Existing character storage; the outer transaction rolls this back if either admission fails.
            DB::table('characters')->where('id', $characterId)->update(['equipment_skills' => implode(',', $input['skills'])]);
            app(ClientStateService::class)->saveEquipment($actor, $characterId, ['revision' => (int) $input['revision'], 'equipment' => $input['equipment']]);
            app(PublishedLoadout::class)->prepareEquipped($actor, $characterId);
            return $this->loadout($actor, $characterId);
        });
    }

    public function start(User $user, array $input): array
    {
        $this->validate($input, ['character_id' => ['required', 'integer', 'between:1,2147483647'], 'revision' => $this->revisionRules(), 'request_key' => ['required', 'uuid']]);
        $input['character_id'] = (int) $input['character_id']; $input['revision'] = (int) $input['revision'];
        return $this->locked($user, function (User $actor) use ($input) {
            $hash = $this->digest($input);
            $old = DB::table('nl_training_runs')->where('user_id', $actor->id)->where('request_key', $input['request_key'])->lockForUpdate()->first();
            if ($old) { abort_unless(hash_equals($old->request_hash, $hash), 409, 'Request key dipakai payload berbeda.'); return ['run' => $this->response($old), 'replayed' => true]; }
            $this->character($actor, $input['character_id']);
            abort_unless($input['revision'] === StateRevision::current((int) $actor->id), 409, 'Loadout berubah; muat ulang.');
            $now = RewardClock::now();
            abort_if(DB::table('nl_training_runs')->where('user_id', $actor->id)->where('character_id', $input['character_id'])->where('status', 'active')->where('expires_at', '>', $now)->exists(), 409, 'Lanjutkan latihan yang masih aktif.');
            $bundle = app(PublishedLoadout::class)->prepareEquipped($actor, $input['character_id']);
            $runtime = app(TrainingRuntime::class); $id = (string) Str::uuid(); $seed = bin2hex(random_bytes(32));
            $result = $runtime->replay($id, $seed, $bundle, []); $json = SkillBlueprint::json($bundle);
            DB::table('nl_training_runs')->insert(['id' => $id, 'user_id' => $actor->id, 'character_id' => $input['character_id'], 'request_key' => $input['request_key'],
                'request_hash' => $hash, 'runtime_hash' => $runtime->fingerprint(), 'bundle' => $json, 'bundle_hash' => hash('sha256', $json), 'seed' => Crypt::encryptString($seed),
                'state' => SkillBlueprint::json($result['state']), 'revision' => 0, 'status' => $result['state']['phase'] === 'ended' ? 'ended' : 'active',
                'expires_at' => $now->copy()->addMinutes(30), 'created_at' => $now, 'updated_at' => $now]);
            return ['run' => $this->response($this->ownedRun($actor, $id)), 'replayed' => false];
        });
    }

    public function get(User $user, string $id): array { return $this->locked($user, fn (User $actor) => $this->response($this->ownedRun($actor, $id))); }

    public function latest(User $user, int $characterId): ?array
    {
        return $this->locked($user, function (User $actor) use ($characterId) {
            $this->character($actor, $characterId);
            $q = DB::table('nl_training_runs')->where('user_id', $actor->id)->where('character_id', $characterId);
            $run = (clone $q)->where('status', 'active')->where('expires_at', '>', RewardClock::now())->first()
                ?? $q->orderByDesc('created_at')->orderByDesc('id')->first();
            return $run ? $this->response($run) : null;
        });
    }

    public function action(User $user, string $id, array $input): array
    {
        $this->validate($input, ['revision' => $this->revisionRules(), 'request_key' => ['required', 'uuid'],
            'action' => ['required', 'in:weapon,skill,charge,dodge,run'], 'skill_id' => ['sometimes', 'required', 'string', 'max:100']]);
        abort_unless(($input['action'] === 'skill') === array_key_exists('skill_id', $input), 422);
        $input['revision'] = (int) $input['revision'];
        return $this->locked($user, function (User $actor) use ($id, $input) {
            $run = $this->ownedRun($actor, $id); $hash = $this->digest($input);
            $old = DB::table('nl_training_actions')->where('run_id', $id)->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Request key dipakai aksi berbeda.');
                return ['run' => $this->response($run), 'accepted_revision' => (int) $old->sequence, 'replayed' => true];
            }
            abort_unless($this->response($run)['status'] !== 'expired', 410, 'Latihan kedaluwarsa.');
            abort_unless($run->status === 'active' && (int) $run->revision === $input['revision'], 409, 'Giliran usang atau latihan berakhir; muat ulang.');
            abort_if((int) $run->revision >= 128, 422, 'Batas aksi latihan tercapai.');
            $runtime = app(TrainingRuntime::class);
            abort_unless(hash_equals($run->runtime_hash, $runtime->fingerprint()), 409, 'Runtime berubah; latihan lama tidak dapat dilanjutkan.');
            abort_unless(hash_equals($run->bundle_hash, hash('sha256', $run->bundle)), 409, 'Integritas snapshot gagal.');
            $state = json_decode($run->state, true, 48, JSON_THROW_ON_ERROR);
            if ($input['action'] === 'skill') {
                $skill = collect($state['skills'])->firstWhere('id', $input['skill_id']);
                abort_unless($skill && $skill['cooldown'] === 0 && $state['player']['cp'] >= $skill['cp_cost'], 422, 'Skill belum tersedia.');
            }
            $rows = DB::table('nl_training_actions')->where('run_id', $id)->orderBy('sequence')->get();
            abort_unless($rows->count() === (int) $run->revision, 409, 'Urutan aksi tidak lengkap.');
            $commands = [];
            foreach ($rows as $i => $row) {
                abort_unless((int) $row->sequence === $i + 1, 409);
                $commands[] = json_decode($row->command, true, 8, JSON_THROW_ON_ERROR);
            }
            $command = ['action' => $input['action']]; if (isset($input['skill_id'])) $command['skill_id'] = $input['skill_id'];
            $commands[] = $command;
            $result = $runtime->replay($id, Crypt::decryptString($run->seed), json_decode($run->bundle, true, 48, JSON_THROW_ON_ERROR), $commands);
            abort_unless(SkillBlueprint::json($result['previous']) === SkillBlueprint::json($state), 409, 'Replay tidak cocok dengan state tersimpan.');
            $revision = (int) $run->revision + 1; $now = RewardClock::now();
            DB::table('nl_training_actions')->insert(['run_id' => $id, 'sequence' => $revision, 'request_key' => $input['request_key'],
                'request_hash' => $hash, 'command' => SkillBlueprint::json($command), 'created_at' => $now]);
            DB::table('nl_training_runs')->where('id', $id)->update(['revision' => $revision, 'state' => SkillBlueprint::json($result['state']),
                'status' => $result['state']['phase'] === 'ended' ? 'ended' : 'active', 'updated_at' => $now]);
            return ['run' => $this->response($this->ownedRun($actor, $id)), 'accepted_revision' => $revision, 'replayed' => false];
        });
    }
}
