<?php

namespace NinjaLost\QA;

use App\Models\User;
use App\Services\Battles\BattleRules;
use App\Services\Battles\BattleService;
use App\Services\Battles\BattleOutcomeVerifier;
use App\Services\Battles\VerifiedBattleOutcome;
use App\Services\Rewards\RewardWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
require_once __DIR__.'/../rewards/Fixture.php';

final class FixtureBattleVerifier implements BattleOutcomeVerifier
{
    public function __construct() { Fixture::guard(); }
    public function verify(string $battleId, array $serverTerms, array $untrustedProof): VerifiedBattleOutcome
    {
        Fixture::guard();
        abort_unless(in_array($untrustedProof, [['qa_evidence' => 'win'], ['qa_evidence' => 'loss']], true), 422);
        // Deliberate test double. Not a game engine, combat replay, or production verifier.
        return new VerifiedBattleOutcome($battleId, $untrustedProof['qa_evidence'] === 'win', BattleService::proofHash($untrustedProof), 'qa-fixture-only', hash('sha256', 'synthetic-verdict:'.$battleId));
    }
}

final class BattleFixture
{
    public static function service(): BattleService { return new BattleService(new RewardWriter(), new FixtureBattleVerifier()); }
    public static function rule(User $admin, int $xp = 120, int $gold = 20, bool $active = true): array
    {
        Fixture::guard(); $key = 'nlqa_msn_'.bin2hex(random_bytes(8));
        DB::table('missions')->insert(['mission_id' => $key, 'req_lvl' => 1, 'gold' => $gold, 'xp' => $xp, 'created_at' => now(), 'updated_at' => now()]);
        $id = app(BattleRules::class)->create($admin, ['slug' => 'nlqa-battle-'.bin2hex(random_bytes(8)), 'mission_id' => $key, 'minimum_seconds' => 1, 'ttl_seconds' => 60]);
        if ($active) { app(BattleRules::class)->setActive($admin, $id, true); }
        return ['id' => $id, 'mission' => $key];
    }
    public static function curve(): void
    {
        Fixture::guard();
        for ($level = 1; $level <= 20; $level++) { DB::table('x_p_s')->updateOrInsert(['level' => $level], ['character_xp' => 100 * $level, 'pet_xp' => 999999999]); }
    }
    public static function start(User $user, int $character, int $rule): array
    {
        return self::service()->start($user, ['character_id' => $character, 'rule_id' => $rule, 'request_key' => (string) Str::uuid()]);
    }
    public static function ready(string $id): void
    {
        Fixture::guard();
        DB::table('nl_battles')->where('id', $id)->update(['not_before' => \App\Support\Rewards\RewardClock::now()->subSecond()]);
    }
    public static function cleanup(array $users, array $rules): void
    {
        Fixture::guard();
        $claims = DB::table('nl_claims')->whereIn('user_id', $users)->pluck('id');
        DB::table('nl_battles')->whereIn('user_id', $users)->delete();
        DB::table('nl_reward_ledger')->whereIn('claim_id', $claims)->delete();
        DB::table('nl_claims')->whereIn('id', $claims)->delete();
        $missions = DB::table('nl_battle_rules')->whereIn('id', $rules)->pluck('mission_id');
        DB::table('nl_battle_rules')->whereIn('id', $rules)->delete();
        foreach ($missions as $key) { if (str_starts_with($key, 'nlqa_')) { DB::table('missions')->where('mission_id', $key)->delete(); } }
        Fixture::cleanup($users, [], []);
    }
}
