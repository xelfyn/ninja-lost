<?php

namespace NinjaLost\QA;

use App\Content\SkillBlueprint;
use App\Evaluation\TrainingRuntime;
use App\Evaluation\TrainingService;
use App\Evaluation\Trials\TrialRules;
use App\Evaluation\Trials\TrialService;
use App\Models\User;
use App\Services\Content\EquipmentStudio;
use App\Services\Rewards\StaffGrantService;
use App\Support\Economy\StateRevision;
use App\Support\Rewards\RewardClock;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
require_once __DIR__.'/../equipment-studio/Fixture.php';
require_once __DIR__.'/../content-studio/Fixture.php';

final class TrialFixture
{
    public static function prepare(bool $equip = true, ?string $password = null): array
    {
        Fixture::guard();
        return DB::transaction(function () use ($equip, $password) {
            $staff = Fixture::actor(99, null, $password); $player = Fixture::actor(0, null, $password); $character = LoadoutFixture::character($player);
            $gear = EquipmentFixture::definition(); $g = EquipmentFixture::release($staff, $gear);
            app(EquipmentStudio::class)->activate($staff, $g['release']['id'], null);
            $skill = StudioFixture::definition(['name' => 'QA Trial Strike', 'damage' => 800, 'cp_cost' => 12, 'cooldown' => 0,
                'effects' => [['code' => 'stun', 'chance' => 100, 'duration' => 1, 'amount' => 0]]]);
            $s = StudioFixture::release($staff, $skill, true);
            $lines = array_map(fn ($p) => ['type' => 'item', 'key' => $p['id'], 'quantity' => 1], $gear['pieces']);
            $lines[] = ['type' => 'skill', 'key' => $skill['id'], 'quantity' => 1];
            app(StaffGrantService::class)->grant($staff, $character->id, $lines, 'QA trial initial ownership', (string) Str::uuid());
            $levels = [];
            for ($i = 1; $i <= 85; $i++) {
                if (!DB::table('x_p_s')->where('level', $i)->exists()) {
                    DB::table('x_p_s')->insert(['level' => $i, 'character_xp' => 100 * $i, 'pet_xp' => 999999999]); $levels[] = $i;
                }
            }
            if ($equip) app(TrainingService::class)->saveLoadout($player, $character->id, ['revision' => StateRevision::current($player->id),
                'equipment' => array_column($gear['pieces'], 'id', 'slot'), 'skills' => [$skill['id']]]);
            return ['staff' => $staff->id, 'player' => $player->id, 'character' => $character->id, 'username' => $player->username, 'staff_email' => $staff->email,
                'gear_draft' => $g['draft']['id'], 'skill_draft' => $s['draft']['id'], 'weapon' => $gear['pieces'][0]['id'], 'back' => $gear['pieces'][1]['id'], 'skill' => $skill['id'], 'levels' => $levels];
        });
    }
    public static function rule(User $staff, array $changes = [], bool $active = true): int
    {
        Fixture::guard(); $id = app(TrialRules::class)->create($staff, array_replace(['slug' => 'qa-trial-'.bin2hex(random_bytes(6)), 'title' => 'QA Mirror Trial',
            'minimum_level' => 1, 'gold' => 25, 'xp' => 120, 'win_limit' => 1], $changes));
        if ($active) app(TrialRules::class)->setActive($staff, $id, true);
        return $id;
    }
    public static function input(array $f, int $trial): array
    {
        return ['character_id' => $f['character'], 'trial_id' => $trial, 'revision' => StateRevision::current($f['player']), 'request_key' => (string) Str::uuid()];
    }
    public static function ready(string $battleId): void
    {
        Fixture::guard();
        // Explicit deterministic QA seed and UTC clock setup; real outcomes still come from the pinned engine.
        DB::transaction(function () use ($battleId) {
            $link = DB::table('nl_trial_runs')->where('battle_id', $battleId)->first();
            $run = DB::table('nl_training_runs')->where('id', $link->run_id)->first();
            if ((int) $run->revision !== 0) throw new \RuntimeException('Only untouched QA runs can be seeded');
            $seed = str_repeat('ab', 32);
            $state = app(TrainingRuntime::class)->replay($run->id, $seed, json_decode($run->bundle, true, 48, JSON_THROW_ON_ERROR), [])['state'];
            DB::table('nl_training_runs')->where('id', $run->id)->update(['seed' => Crypt::encryptString($seed), 'state' => SkillBlueprint::json($state)]);
            DB::table('nl_battles')->where('id', $battleId)->update(['not_before' => RewardClock::now()->subSecond()]);
        });
    }
    public static function win(User $player, array $trial): array
    {
        Fixture::guard(); $service = app(TrialService::class);
        for ($i = 0; $trial['run']['status'] === 'active' && $i < 16; $i++) {
            $skill = $trial['run']['state']['skills'][0];
            $trial = $service->action($player, $trial['battle_id'], ['revision' => $trial['run']['revision'], 'request_key' => (string) Str::uuid(),
                'action' => 'skill', 'skill_id' => $skill['id']])['trial'];
        }
        if ($trial['run']['state']['outcome']['won'] !== true) throw new \RuntimeException('Fixture did not win via actual engine');
        return $trial;
    }
    public static function cleanup(array $f): void
    {
        Fixture::guard(); $users = [$f['staff'], $f['player']];
        DB::transaction(function () use ($f, $users) {
            $actors = DB::table('users')->whereIn('id', $users)->get();
            if ($actors->count() !== 2 || $actors->contains(fn ($u) => !str_starts_with($u->username, 'nlqa_'))) throw new \RuntimeException('Only owned QA users');
            $trials = DB::table('nl_trial_rules')->where('created_by', $f['staff'])->get();
            if ($trials->contains(fn ($t) => !str_starts_with($t->slug, 'qa-trial-'))) throw new \RuntimeException('Only owned QA rules');
            $rules = DB::table('nl_battle_rules')->whereIn('id', $trials->pluck('battle_rule_id'))->get();
            DB::table('nl_trial_runs')->whereIn('user_id', $users)->delete();
            DB::table('nl_battles')->whereIn('user_id', $users)->delete();
            $runs = DB::table('nl_training_runs')->whereIn('user_id', $users)->pluck('id');
            DB::table('nl_training_actions')->whereIn('run_id', $runs)->delete(); DB::table('nl_training_runs')->whereIn('id', $runs)->delete();
            DB::table('nl_trial_rules')->whereIn('id', $trials->pluck('id'))->delete();
            DB::table('nl_battle_rules')->whereIn('id', $rules->pluck('id'))->delete();
            foreach ($rules as $r) {
                if (!str_starts_with($r->mission_id, 'nl_trial_')) throw new \RuntimeException('Unexpected mission provenance');
                DB::table('missions')->where('mission_id', $r->mission_id)->delete();
            }
            $claims = DB::table('nl_claims')->whereIn('user_id', $users)->pluck('id');
            DB::table('nl_reward_ledger')->whereIn('claim_id', $claims)->delete(); DB::table('nl_claims')->whereIn('id', $claims)->delete();
            DB::table('sessions')->whereIn('user_id', $users)->delete();
            StudioFixture::cleanup([], [$f['skill_draft']]); EquipmentFixture::cleanup($f['gear_draft'], $users);
            foreach ($f['levels'] as $level) DB::table('x_p_s')->where('level', $level)->where('character_xp', 100 * $level)->where('pet_xp', 999999999)->delete();
        });
    }
}
