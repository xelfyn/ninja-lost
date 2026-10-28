<?php

declare(strict_types=1);
namespace App\Evaluation\Trials;

use App\Models\User;
use App\Services\Battles\BattleRules;
use App\Support\Rewards\RewardClock;
use App\Support\Rewards\RewardRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Explicit mirror trials only. Never associates an existing arbitrary mission with a mirror victory. */
final class TrialRules
{
    private function staff(User $actor, callable $operation): mixed
    {
        abort_unless(TrialBoundary::enabled(), 404);
        return DB::transaction(function () use ($actor, $operation) {
            $user = User::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($user && (int) $user->account_type === 99, 403);
            return $operation($user);
        }, 3);
    }
    public function create(User $actor, array $input): int
    {
        Validator::make(['trial' => $input], [
            'trial' => ['required', 'array:slug,title,minimum_level,gold,xp,win_limit'],
            'trial.slug' => ['required', 'string', 'regex:/\A[a-z0-9-]{1,60}\z/D'],
            'trial.title' => ['required', 'string', 'max:120'],
            'trial.minimum_level' => ['required', 'integer', 'between:1,85'],
            'trial.gold' => ['required', 'integer', 'between:0,100000000'],
            'trial.xp' => ['required', 'integer', 'between:0,100000000'],
            'trial.win_limit' => ['required', 'integer', 'between:1,1000'],
        ])->validate();
        if ((int) $input['gold'] === 0 && (int) $input['xp'] === 0) RewardRules::reject('Tentukan reward gold atau XP.');
        return $this->staff($actor, function (User $staff) use ($input) {
            if (DB::table('nl_trial_rules')->where('slug', $input['slug'])->exists()) RewardRules::reject('Slug tantangan sudah digunakan.');
            $mission = 'nl_trial_'.bin2hex(random_bytes(16));
            DB::table('missions')->insert(['mission_id' => $mission, 'req_lvl' => (int) $input['minimum_level'],
                'gold' => (int) $input['gold'], 'xp' => (int) $input['xp'], 'created_at' => RewardClock::now(), 'updated_at' => RewardClock::now()]);
            $rule = app(BattleRules::class)->create($staff, ['slug' => 'trial-'.$input['slug'], 'mission_id' => $mission, 'minimum_seconds' => 1, 'ttl_seconds' => 1800]);
            $id = DB::table('nl_trial_rules')->insertGetId(['slug' => $input['slug'], 'title' => $input['title'], 'battle_rule_id' => $rule,
                'win_limit' => (int) $input['win_limit'], 'created_by' => $staff->id, 'created_at' => RewardClock::now()]);
            DB::table('nl_operations_audit')->insert(['actor_id' => $staff->id, 'action' => 'trial.create', 'subject' => 'trial:'.$id,
                'details' => json_encode(['mode' => 'mirror-v1', 'input' => $input, 'active' => false], JSON_THROW_ON_ERROR), 'created_at' => RewardClock::now()]);
            return $id;
        });
    }
    public function setActive(User $actor, int $id, bool $active): void
    {
        $this->staff($actor, function (User $staff) use ($id, $active) {
            $trial = DB::table('nl_trial_rules')->where('id', $id)->lockForUpdate()->first();
            abort_unless($trial, 404);
            app(BattleRules::class)->setActive($staff, (int) $trial->battle_rule_id, $active);
        });
    }
    public static function wins(int $userId, int $trialId): int
    {
        return DB::table('nl_trial_runs as t')->join('nl_battles as b', 'b.id', '=', 't.battle_id')
            ->where('t.user_id', $userId)->where('t.trial_id', $trialId)->where('b.status', 'won')->count();
    }
}
