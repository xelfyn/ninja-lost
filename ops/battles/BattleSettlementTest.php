<?php

use App\Models\User;
use App\Services\Battles\BattleService;
use App\Services\Battles\BattleRules;
use App\Services\Battles\BattleOutcomeVerifier;
use App\Services\Battles\VerifiedBattleOutcome;
use App\Services\Rewards\RewardWriter;
use App\Services\Rewards\StaffGrantService;
use App\Services\Rewards\ClientStateService;
use App\Support\Rewards\RewardClock;
use App\Support\Economy\StateRevision;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use NinjaLost\QA\Fixture;
use NinjaLost\QA\BattleFixture;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
require_once __DIR__.'/Fixture.php';

final class BattleSettlementTest extends TestCase
{
    use DatabaseTransactions;
    private User $admin;
    private User $player;
    private App\Models\Character $character;
    private array $rule;
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $app;
    }
    protected function setUp(): void
    {
        parent::setUp(); Fixture::guard(); BattleFixture::curve();
        $this->admin = Fixture::actor(99); $this->player = Fixture::actor(); $this->character = Fixture::character($this->player);
        $this->character->update(['gold' => 30, 'level' => 1, 'xp' => 0, 'rank' => 1]); $this->rule = BattleFixture::rule($this->admin);
    }
    private function start(array $overrides = []): array
    {
        // Start needs no test verdict provider. Exercise the actual default service,
        // including its production denial without constructing a CI-only fixture there.
        return app(BattleService::class)->start($this->player, array_replace(['character_id' => $this->character->id, 'rule_id' => $this->rule['id'], 'request_key' => (string) Str::uuid()], $overrides));
    }
    private function input(string $id, string $outcome = 'win'): array { return ['battle_id' => $id, 'proof' => ['qa_evidence' => $outcome]]; }
    private function finish(string $id, string $outcome = 'win'): array { return BattleFixture::service()->finish($this->player, $this->input($id, $outcome)); }
    private function denied(int $status, callable $fn): void
    {
        $caught = null; try { $fn(); } catch (HttpExceptionInterface $e) { $caught = $e; }
        $this->assertInstanceOf(HttpExceptionInterface::class, $caught); $this->assertSame($status, $caught->getStatusCode());
    }
    private function invalid(callable $fn): void
    {
        $caught = null; try { $fn(); } catch (ValidationException $e) { $caught = $e; }
        $this->assertInstanceOf(ValidationException::class, $caught);
    }
    private function unchanged(): void
    {
        $c = $this->character->fresh(); $this->assertSame(30, (int) $c->gold); $this->assertSame(0, (int) $c->xp); $this->assertSame(1, (int) $c->level);
        $this->assertSame(0, StateRevision::current($this->player->id)); $this->assertSame(0, DB::table('nl_claims')->where('user_id', $this->player->id)->count());
    }
    public function test_default_runtime_rejects_unverified_win_flags_or_hashes(): void
    {
        $ticket = $this->start(); BattleFixture::ready($ticket['battle_id']);
        foreach ([[], ['won' => true], ['hash' => md5('known-payload')], ['qa_evidence' => 'win']] as $proof) {
            $this->denied(503, fn () => app(BattleService::class)->finish($this->player, ['battle_id' => $ticket['battle_id'], 'proof' => $proof])); $this->unchanged();
        }
        $this->assertDatabaseHas('nl_battles', ['id' => $ticket['battle_id'], 'status' => 'started', 'claim_id' => null]);
    }
    public function test_verified_settlement_records_atomic_gold_xp_and_level(): void
    {
        $ticket = $this->start(); BattleFixture::ready($ticket['battle_id']); $r = $this->finish($ticket['battle_id']);
        $this->assertFalse($r['replayed']); $this->assertSame('won', $r['outcome']);
        $c = $this->character->fresh(); $this->assertSame(50, (int) $c->gold); $this->assertSame(120, (int) $c->xp); $this->assertSame(2, (int) $c->level);
        $this->assertSame(1, StateRevision::current($this->player->id));
        $this->assertDatabaseHas('nl_claims', ['id' => $r['claim_id'], 'source' => 'battle_settlement', 'user_id' => $this->player->id]);
        $this->assertDatabaseHas('nl_reward_ledger', ['claim_id' => $r['claim_id'], 'kind' => 'gold', 'quantity' => 20, 'direction' => 'credit']);
        $this->assertDatabaseHas('nl_reward_ledger', ['claim_id' => $r['claim_id'], 'kind' => 'xp', 'quantity' => 120, 'balance_before' => 0, 'balance_after' => 120]);
        $this->assertSame(2, DB::table('nl_reward_ledger')->count());
    }
    public function test_start_and_finish_retries_return_original_without_new_reward(): void
    {
        $key = (string) Str::uuid(); $first = $this->start(['request_key' => $key]); $again = $this->start(['request_key' => $key]);
        $this->assertSame($first['battle_id'], $again['battle_id']); $this->assertTrue($again['replayed']);
        BattleFixture::ready($first['battle_id']); $result = $this->finish($first['battle_id']);
        DB::table('nl_battles')->where('id', $first['battle_id'])->update(['expires_at' => RewardClock::now()->subDay()]);
        app(BattleRules::class)->setActive($this->admin, $this->rule['id'], false);
        $retry = $this->finish($first['battle_id']); $this->assertTrue($retry['replayed']); $this->assertSame($result['claim_id'], $retry['claim_id']);
        $this->assertSame(50, (int) $this->character->fresh()->gold); $this->assertSame(1, DB::table('nl_claims')->count());
        $this->invalid(fn () => $this->finish($first['battle_id'], 'loss'));
    }
    public function test_verified_loss_is_terminal_and_does_not_issue_rewards(): void
    {
        $t = $this->start(); BattleFixture::ready($t['battle_id']); $r = $this->finish($t['battle_id'], 'loss');
        $this->assertSame('lost', $r['outcome']); $this->assertNull($r['claim_id']); $this->unchanged();
        $this->assertTrue($this->finish($t['battle_id'], 'loss')['replayed']); $this->invalid(fn () => $this->finish($t['battle_id']));
    }
    public function test_early_and_expired_finish_never_issue_rewards(): void
    {
        $t = $this->start();
        DB::table('nl_battles')->where('id', $t['battle_id'])->update(['not_before' => RewardClock::now()->addMinute()]);
        $this->denied(409, fn () => $this->finish($t['battle_id']));
        DB::table('nl_battles')->where('id', $t['battle_id'])->update(['expires_at' => RewardClock::now()]);
        $this->denied(410, fn () => $this->finish($t['battle_id'])); $this->unchanged();
    }
    public function test_one_active_session_and_start_key_payload_conflict(): void
    {
        $key = (string) Str::uuid(); $t = $this->start(['request_key' => $key]);
        $this->denied(409, fn () => $this->start());
        $other = Fixture::character($this->player);
        $this->invalid(fn () => $this->start(['character_id' => $other->id, 'request_key' => $key]));
        DB::table('nl_battles')->where('id', $t['battle_id'])->update(['expires_at' => RewardClock::now()->subSecond()]);
        $this->assertNotSame($t['battle_id'], $this->start()['battle_id']); $this->unchanged();
    }
    public function test_foreign_character_battle_and_revoked_account_are_denied(): void
    {
        $other = Fixture::actor(); $character = Fixture::character($other);
        $this->denied(404, fn () => $this->start(['character_id' => $character->id]));
        $t = $this->start(); BattleFixture::ready($t['battle_id']);
        $this->denied(404, fn () => BattleFixture::service()->finish($other, $this->input($t['battle_id'])));
        DB::table('users')->where('id', $this->player->id)->update(['account_type' => 2]);
        $this->denied(403, fn () => $this->finish($t['battle_id'])); $this->unchanged();
    }
    public function test_client_cannot_supply_rewards_or_server_verdict(): void
    {
        foreach (['gold', 'xp', 'won', 'actor_id', 'mission_id'] as $key) { $this->invalid(fn () => $this->start([$key => 100])); }
        $t = $this->start(); BattleFixture::ready($t['battle_id']);
        foreach (['tokens', 'outcome', 'verifier', 'claim_id', 'character_id'] as $key) {
            $this->invalid(fn () => BattleFixture::service()->finish($this->player, $this->input($t['battle_id']) + [$key => 1]));
        }
        $this->unchanged();
    }
    public function test_rule_requires_explicit_activation_and_unchanged_definition(): void
    {
        $r = BattleFixture::rule($this->admin, 120, 20, false);
        $this->assertDatabaseHas('nl_battle_rules', ['id' => $r['id'], 'active' => false]);
        $this->invalid(fn () => $this->start(['rule_id' => $r['id']]));
        DB::table('missions')->where('mission_id', $r['mission'])->update(['gold' => 99]);
        $this->invalid(fn () => app(BattleRules::class)->setActive($this->admin, $r['id'], true));
        $this->unchanged();
    }
    public function test_inflight_terms_are_frozen_and_staff_grant_does_not_block_finish(): void
    {
        $t = $this->start(); BattleFixture::ready($t['battle_id']);
        DB::table('missions')->where('mission_id', $this->rule['mission'])->update(['gold' => 999, 'xp' => 999]);
        app(BattleRules::class)->setActive($this->admin, $this->rule['id'], false);
        app(StaffGrantService::class)->grant($this->admin, $this->character->id, [['type' => 'gold', 'key' => '', 'quantity' => 7]], 'QA reward during active battle', (string) Str::uuid());
        $this->finish($t['battle_id']); $this->assertSame(57, (int) $this->character->fresh()->gold); $this->assertSame(120, (int) $this->character->fresh()->xp);
        $this->assertSame(2, StateRevision::current($this->player->id));
    }
    public function test_progression_change_conflicts_without_overwriting_current_state(): void
    {
        $t = $this->start(); BattleFixture::ready($t['battle_id']); $this->character->update(['xp' => 9]);
        $this->denied(409, fn () => $this->finish($t['battle_id']));
        $this->assertSame(9, (int) $this->character->fresh()->xp); $this->assertSame(30, (int) $this->character->fresh()->gold); $this->assertSame(0, DB::table('nl_claims')->count());
    }
    public function test_rank_cap_tracks_requested_and_effective_xp_without_losing_old_xp(): void
    {
        $this->character->update(['xp' => 1990, 'level' => 20]); $t = $this->start(); BattleFixture::ready($t['battle_id']);
        $r = $this->finish($t['battle_id']); $xp = $r['granted'][1];
        $this->assertSame(120, $xp['requested']); $this->assertSame(10, $xp['quantity']); $this->assertSame(2000, $xp['after']);
        $this->assertSame(20, (int) $this->character->fresh()->level); $this->assertDatabaseHas('nl_reward_ledger', ['kind' => 'xp', 'quantity' => 10]);
    }
    public function test_missing_nonmonotonic_and_overcap_progression_fail_closed(): void
    {
        DB::table('x_p_s')->where('level', 3)->delete(); $this->invalid(fn () => $this->start());
        BattleFixture::curve(); DB::table('x_p_s')->where('level', 3)->update(['character_xp' => 1]); $this->invalid(fn () => $this->start());
        BattleFixture::curve(); $this->character->update(['level' => 20, 'xp' => 2001]); $this->invalid(fn () => $this->start());
        $this->assertSame(2001, (int) $this->character->fresh()->xp); $this->assertSame(0, DB::table('nl_battles')->count());
    }
    public function test_bonus_is_captured_at_start_using_utc(): void
    {
        $this->character->update(['double_xp_expire_at' => RewardClock::now()->addHour(), 'xp_bonus_rate' => 50]);
        $t = $this->start(); BattleFixture::ready($t['battle_id']);
        $this->character->update(['xp_bonus_rate' => 1000]); $this->finish($t['battle_id']);
        $this->assertSame(180, (int) $this->character->fresh()->xp);
    }
    public function test_gold_only_and_xp_only_settlements_each_increment_revision_once(): void
    {
        $r = BattleFixture::rule($this->admin, 0, 20); $t = $this->start(['rule_id' => $r['id']]); BattleFixture::ready($t['battle_id']); $this->finish($t['battle_id']);
        $this->assertSame(1, StateRevision::current($this->player->id));
        $r = BattleFixture::rule($this->admin, 120, 0); $t = $this->start(['rule_id' => $r['id']]); BattleFixture::ready($t['battle_id']); $this->finish($t['battle_id']);
        $this->assertSame(2, StateRevision::current($this->player->id)); $this->assertSame(50, (int) $this->character->fresh()->gold); $this->assertSame(120, (int) $this->character->fresh()->xp);
    }
    public function test_pet_scope_and_staff_revocation_are_not_silently_ignored(): void
    {
        $this->character->update(['equipment_pet' => 1]); $this->invalid(fn () => $this->start());
        DB::table('users')->where('id', $this->admin->id)->update(['account_type' => 0]);
        $this->denied(403, fn () => app(BattleRules::class)->setActive($this->admin, $this->rule['id'], false));
        $this->unchanged();
    }
    public function test_bounded_proof_size_and_nesting_are_enforced(): void
    {
        $t = $this->start(); BattleFixture::ready($t['battle_id']);
        $this->invalid(fn () => BattleFixture::service()->finish($this->player, ['battle_id' => $t['battle_id'], 'proof' => ['x' => str_repeat('x', 65537)]]));
        $proof = ['x' => 1]; for ($i = 0; $i < 17; $i++) { $proof = ['x' => $proof]; }
        $this->invalid(fn () => BattleFixture::service()->finish($this->player, ['battle_id' => $t['battle_id'], 'proof' => $proof])); $this->unchanged();
    }
    public function test_foreign_or_unbound_verifier_result_never_grants(): void
    {
        $t = $this->start(); BattleFixture::ready($t['battle_id']);
        $verifier = new class implements BattleOutcomeVerifier {
            public function verify(string $id, array $terms, array $proof): VerifiedBattleOutcome
            { return new VerifiedBattleOutcome((string) Str::uuid(), true, BattleService::proofHash($proof), 'broken-provider', hash('sha256', 'qa')); }
        };
        $caught = null; try { (new BattleService(new RewardWriter(), $verifier))->finish($this->player, $this->input($t['battle_id'])); } catch (LogicException $e) { $caught = $e; }
        $this->assertInstanceOf(LogicException::class, $caught); $this->unchanged();
    }
    public function test_fault_after_gold_write_rolls_back_progression_claim_and_session(): void
    {
        $t = $this->start(); BattleFixture::ready($t['battle_id']);
        $writer = new class extends RewardWriter {
            public function apply(object $a, object $c, array $l, ?object $catalog): array { parent::apply($a, $c, $l, $catalog); throw new RuntimeException('qa-gold-fault'); }
        };
        $caught = null;
        try { (new BattleService($writer, new NinjaLost\QA\FixtureBattleVerifier()))->finish($this->player, $this->input($t['battle_id'])); } catch (RuntimeException $e) { $caught = $e; }
        $this->assertSame('qa-gold-fault', $caught?->getMessage()); $this->unchanged();
        $this->assertDatabaseHas('nl_battles', ['id' => $t['battle_id'], 'status' => 'started', 'claim_id' => null]);
    }
    public function test_late_settlement_fault_rolls_back_ledger_and_all_mutations(): void
    {
        $t = $this->start(); BattleFixture::ready($t['battle_id']); $armed = true;
        DB::listen(function ($q) use (&$armed) { if ($armed && str_starts_with($q->sql, 'update `nl_battles`')) { throw new RuntimeException('qa-terminal-fault'); } });
        $caught = null; try { $this->finish($t['battle_id']); } catch (RuntimeException $e) { $caught = $e; } finally { $armed = false; }
        $this->assertSame('qa-terminal-fault', $caught?->getMessage()); $this->unchanged(); $this->assertSame(0, DB::table('nl_reward_ledger')->count());
        $this->assertSame('won', $this->finish($t['battle_id'])['outcome']);
    }
    public function test_revision_overflow_rolls_back_gold_and_xp(): void
    {
        $t = $this->start(); BattleFixture::ready($t['battle_id']); DB::table('nl_account_revisions')->insert(['user_id' => $this->player->id, 'revision' => 4294967295]);
        $this->invalid(fn () => $this->finish($t['battle_id']));
        $this->assertSame(30, (int) $this->character->fresh()->gold); $this->assertSame(0, (int) $this->character->fresh()->xp); $this->assertSame(0, DB::table('nl_claims')->count());
    }
    public function test_finished_battle_invalidates_old_equipment_without_changing_new_reward(): void
    {
        $snapshot = app(ClientStateService::class)->snapshot($this->player, $this->character->id);
        $t = $this->start(); BattleFixture::ready($t['battle_id']); $this->finish($t['battle_id']);
        $this->denied(409, fn () => app(ClientStateService::class)->saveEquipment($this->player, $this->character->id, ['revision' => $snapshot['revision'], 'equipment' => ['weapon' => '']]));
        $this->assertSame(50, (int) $this->character->fresh()->gold);
    }
    public function test_no_public_battle_route_or_production_service_activation(): void
    {
        foreach (['/amf', '/gateway.php', '/api/battles/start', '/api/battles/finish'] as $url) { $this->postJson($url, ['won' => true])->assertNotFound(); }
        app()->instance('env', 'production');
        try { $this->denied(403, fn () => $this->start()); } finally { app()->instance('env', 'testing'); }
    }
    public function test_migration_refuses_destructive_down(): void
    {
        $caught = null;
        try { (require base_path('database/migrations/2026_10_28_000003_add_battle_settlement.php'))->down(); } catch (RuntimeException $e) { $caught = $e; }
        $this->assertInstanceOf(RuntimeException::class, $caught); $this->assertTrue(Illuminate\Support\Facades\Schema::hasTable('nl_battles'));
    }
}
