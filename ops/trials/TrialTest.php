<?php

use App\Content\SkillBlueprint;
use App\Evaluation\GatewayAccess;
use App\Evaluation\TrainingService;
use App\Evaluation\Trials\TrialRules;
use App\Evaluation\Trials\TrialService;
use App\Evaluation\Trials\TrialVerifier;
use App\Models\User;
use App\Services\Battles\BattleService;
use App\Services\Rewards\StaffGrantService;
use App\Support\Economy\StateRevision;
use App\Support\Rewards\RewardClock;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use NinjaLost\QA\Fixture;
use NinjaLost\QA\LoadoutFixture;
use NinjaLost\QA\TrialFixture as TF;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
require_once __DIR__.'/Fixture.php';

final class TrialTest extends TestCase
{
    use DatabaseTransactions;
    private array $f;
    private User $staff;
    private User $player;
    private int $rule;
    public function createApplication() { $app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $app; }
    protected function setUp(): void
    {
        parent::setUp(); Fixture::guard(); $this->f = TF::prepare(); $this->staff = User::findOrFail($this->f['staff']); $this->player = User::findOrFail($this->f['player']); $this->rule = TF::rule($this->staff);
    }
    private function service(): TrialService { return app(TrialService::class); }
    private function start(): array
    {
        $trial = $this->service()->start($this->player, TF::input($this->f, $this->rule))['trial']; TF::ready($trial['battle_id']); return $this->service()->get($this->player, $trial['battle_id']);
    }
    private function terminal(): array { return TF::win($this->player, $this->start()); }
    private function deny(int $status, callable $fn): void
    {
        $e = null; try { $fn(); } catch (HttpExceptionInterface $error) { $e = $error; }
        $this->assertNotNull($e); $this->assertSame($status, $e->getStatusCode());
    }
    private function invalid(callable $fn): void
    {
        $e = null; try { $fn(); } catch (ValidationException $error) { $e = $error; }
        $this->assertInstanceOf(ValidationException::class, $e);
    }
    private function economy(): array
    {
        return ['character' => (array) DB::table('characters')->where('id', $this->f['character'])->first(['gold', 'xp', 'level']),
            'tokens' => (int) $this->player->fresh()->tokens, 'revision' => StateRevision::current($this->player->id),
            'claims' => DB::table('nl_claims')->count(), 'ledger' => DB::table('nl_reward_ledger')->count()];
    }
    private function rawBattle(array $trial): object { return DB::table('nl_battles')->where('id', $trial['battle_id'])->first(); }
    public function test_rules_default_inactive_have_a_unique_internal_mission_and_are_not_arbitrary_links(): void
    {
        $id = TF::rule($this->staff, [], false); $row = DB::table('nl_trial_rules')->where('id', $id)->first();
        $rule = DB::table('nl_battle_rules')->where('id', $row->battle_rule_id)->first();
        $this->assertFalse((bool) $rule->active); $this->assertStringStartsWith('nl_trial_', $rule->mission_id);
        $this->invalid(fn () => TF::rule($this->staff, ['mission_id' => 'existing-mission']));
        $this->invalid(fn () => TF::rule($this->staff, ['gold' => 0, 'xp' => 0]));
        $this->invalid(fn () => TF::rule($this->staff, ['win_limit' => 0]));
    }
    public function test_start_is_atomic_idempotent_and_retains_equipment_and_reward_snapshots(): void
    {
        $input = TF::input($this->f, $this->rule); $a = $this->service()->start($this->player, $input); $b = $this->service()->start($this->player, $input);
        $this->assertSame($a['trial'], $b['trial']); $this->assertTrue($b['replayed']);
        $this->assertSame(560, $a['trial']['run']['state']['player']['max_hp']); $this->assertSame(['gold' => 25, 'xp' => 120], $a['trial']['rewards']);
        foreach (['nl_trial_runs', 'nl_battles', 'nl_training_runs'] as $table) $this->assertSame(1, DB::table($table)->count());
        $input['revision']++; $this->deny(409, fn () => $this->service()->start($this->player, $input));
    }
    public function test_real_knockout_settles_gold_xp_receipt_revision_and_ledger_once(): void
    {
        $before = $this->economy(); $trial = $this->terminal(); $this->assertSame($before, $this->economy());
        $this->assertLessThanOrEqual(0, $trial['run']['state']['mirror']['hp']);
        $a = $this->service()->finish($this->player, $trial['battle_id'], []); $b = $this->service()->finish($this->player, $trial['battle_id'], []);
        $this->assertFalse($a['replayed']); $this->assertTrue($b['replayed']); $this->assertSame($a['trial'], $b['trial']);
        $this->assertSame('won', $a['trial']['result']['outcome']); $this->assertNotNull($a['trial']['result']['claim_id']);
        $after = $this->economy(); $this->assertSame(25, (int) $after['character']['gold']); $this->assertSame(120, (int) $after['character']['xp']);
        $this->assertSame($before['revision'] + 1, $after['revision']); $this->assertSame($before['claims'] + 1, $after['claims']);
        $this->assertSame($before['ledger'] + 2, $after['ledger']); $this->assertSame(1, TrialRules::wins($this->player->id, $this->rule));
        $this->assertSame('mirror-replay-v1', $this->rawBattle($trial)->verifier);
    }
    public function test_loss_is_engine_verified_without_reward_and_does_not_consume_win_quota(): void
    {
        $before = $this->economy(); $t = $this->start();
        $this->service()->action($this->player, $t['battle_id'], ['revision' => 0, 'request_key' => (string) Str::uuid(), 'action' => 'run']);
        $r = $this->service()->finish($this->player, $t['battle_id'], []);
        $this->assertSame('lost', $r['trial']['result']['outcome']); $this->assertNull($r['trial']['result']['claim_id']);
        $this->assertSame($before, $this->economy()); $this->assertSame(0, TrialRules::wins($this->player->id, $this->rule)); $this->assertNotSame($t['battle_id'], $this->start()['battle_id']);
    }
    public function test_unfinished_and_client_declared_win_or_gold_are_never_settled(): void
    {
        $before = $this->economy(); $t = $this->start();
        $this->deny(409, fn () => $this->service()->finish($this->player, $t['battle_id'], []));
        foreach ([['won' => true], ['gold' => 999], ['proof' => ['won' => true]]] as $payload) $this->deny(422, fn () => $this->service()->finish($this->player, $t['battle_id'], $payload));
        $this->assertSame($before, $this->economy());
    }
    public function test_standalone_training_cannot_be_substituted_for_a_trial_or_attach_to_a_mission(): void
    {
        $run = app(TrainingService::class)->start($this->player, ['character_id' => $this->f['character'], 'revision' => StateRevision::current($this->player->id), 'request_key' => (string) Str::uuid()])['run'];
        $this->deny(404, fn () => $this->service()->finish($this->player, $run['id'], []));
        $this->deny(409, fn () => $this->start()); $this->assertSame(0, DB::table('nl_battles')->count()); $this->assertSame(0, DB::table('nl_trial_runs')->count());
    }
    public function test_default_legacy_verifier_remains_unavailable_even_after_a_real_trial_knockout(): void
    {
        $t = $this->terminal(); $before = $this->economy();
        $this->deny(503, fn () => app(BattleService::class)->finish($this->player, ['battle_id' => $t['battle_id'], 'proof' => ['run_id' => $t['run']['id']]]));
        $this->assertSame($before, $this->economy());
    }
    public function test_forged_stored_victory_and_modified_transcript_fail_replay_without_credit(): void
    {
        $t = $this->start(); $state = $t['run']['state']; $state['phase'] = 'ended'; $state['outcome'] = ['won' => true, 'reason' => 'forged'];
        DB::table('nl_training_runs')->where('id', $t['run']['id'])->update(['status' => 'ended', 'state' => SkillBlueprint::json($state)]);
        $before = $this->economy(); $this->deny(409, fn () => $this->service()->finish($this->player, $t['battle_id'], [])); $this->assertSame($before, $this->economy());
    }
    public function test_reward_terms_cannot_be_changed_to_monetize_an_already_bound_result(): void
    {
        $t = $this->terminal(); $battle = $this->rawBattle($t); $terms = json_decode($battle->terms, true); $terms['rewards'][0]['quantity'] = 999;
        DB::table('nl_battles')->where('id', $battle->id)->update(['terms' => json_encode($terms)]);
        $before = $this->economy(); $this->deny(409, fn () => $this->service()->finish($this->player, $battle->id, [])); $this->assertSame($before, $this->economy());
    }
    public function test_runtime_and_public_state_drift_are_rejected_at_final_verification(): void
    {
        $t = $this->terminal(); $before = $this->economy(); $row = DB::table('nl_training_runs')->where('id', $t['run']['id'])->first();
        DB::table('nl_training_runs')->where('id', $row->id)->update(['runtime_hash' => str_repeat('0', 64)]);
        $this->deny(409, fn () => $this->service()->finish($this->player, $t['battle_id'], []));
        $state = json_decode($row->state, true); $state['player']['hp']++;
        DB::table('nl_training_runs')->where('id', $row->id)->update(['runtime_hash' => $row->runtime_hash, 'state' => SkillBlueprint::json($state)]);
        $this->deny(409, fn () => $this->service()->finish($this->player, $t['battle_id'], [])); $this->assertSame($before, $this->economy());
    }
    public function test_start_time_reward_terms_survive_pause_and_metadata_edits(): void
    {
        $t = $this->terminal(); app(TrialRules::class)->setActive($this->staff, $this->rule, false);
        $rule = DB::table('nl_battle_rules')->where('id', $this->rawBattle($t)->rule_id)->first();
        DB::table('missions')->where('mission_id', $rule->mission_id)->update(['gold' => 999]);
        $this->assertSame('won', $this->service()->finish($this->player, $t['battle_id'], [])['trial']['result']['outcome']);
        $this->assertSame(25, (int) $this->economy()['character']['gold']);
        $this->invalid(fn () => app(TrialRules::class)->setActive($this->staff, $this->rule, true));
    }
    public function test_win_quota_is_account_scoped_and_second_character_cannot_evade_it(): void
    {
        $t = $this->terminal(); $this->service()->finish($this->player, $t['battle_id'], []);
        $other = LoadoutFixture::character($this->player); $input = TF::input($this->f, $this->rule); $input['character_id'] = $other->id;
        $this->deny(409, fn () => $this->service()->start($this->player, $input)); $this->assertSame(1, DB::table('nl_trial_runs')->count());
    }
    public function test_only_one_unsettled_trial_per_account_and_start_failure_leaves_no_orphans(): void
    {
        $this->start(); $other = LoadoutFixture::character($this->player); $input = TF::input($this->f, $this->rule); $input['character_id'] = $other->id;
        $this->deny(409, fn () => $this->service()->start($this->player, $input));
        foreach (['nl_trial_runs', 'nl_battles', 'nl_training_runs'] as $table) $this->assertSame(1, DB::table($table)->count());
    }
    public function test_external_grants_are_preserved_and_signed_gold_ledger_reconciles(): void
    {
        $t = $this->terminal();
        app(StaffGrantService::class)->grant($this->staff, $this->f['character'], [['type' => 'gold', 'key' => '', 'quantity' => 7]], 'QA during trial', (string) Str::uuid());
        $this->service()->finish($this->player, $t['battle_id'], []); $balance = 0;
        foreach (DB::table('nl_reward_ledger')->where('kind', 'gold')->orderBy('id')->get() as $entry) {
            $this->assertSame($balance, (int) $entry->balance_before); $balance += (int) $entry->quantity; $this->assertSame($balance, (int) $entry->balance_after);
        }
        $this->assertSame(32, $balance); $this->assertSame(32, (int) $this->economy()['character']['gold']);
    }
    public function test_xp_curve_is_used_for_level_up_and_new_progression_invalidates_old_snapshot(): void
    {
        DB::table('characters')->where('id', $this->f['character'])->update(['xp' => 990]);
        $t = $this->terminal(); $this->service()->finish($this->player, $t['battle_id'], []);
        $this->assertSame(1110, (int) $this->economy()['character']['xp']); $this->assertSame(12, (int) $this->economy()['character']['level']);
        // Cumulative thresholds: level10 ->11 at1000, then ->12 at1100.
    }
    public function test_changed_progression_cannot_be_overwritten_by_settlement(): void
    {
        $t = $this->terminal(); DB::table('characters')->where('id', $this->f['character'])->update(['xp' => 1]);
        $before = $this->economy(); $this->deny(409, fn () => $this->service()->finish($this->player, $t['battle_id'], [])); $this->assertSame($before, $this->economy());
    }
    public function test_late_ledger_fault_rolls_back_xp_gold_revision_receipt_and_result(): void
    {
        $t = $this->terminal(); $before = $this->economy(); $armed = true; $error = null;
        DB::listen(function ($query) use (&$armed) { if ($armed && str_starts_with($query->sql, 'insert into `nl_reward_ledger`')) { $armed = false; throw new RuntimeException('qa-trial-late-fault'); } });
        try { $this->service()->finish($this->player, $t['battle_id'], []); } catch (RuntimeException $e) { $error = $e; } finally { $armed = false; }
        $this->assertSame('qa-trial-late-fault', $error?->getMessage()); $this->assertSame($before, $this->economy()); $this->assertNull($this->rawBattle($t)->result);
        $this->assertSame('won', $this->service()->finish($this->player, $t['battle_id'], [])['trial']['result']['outcome']);
    }
    public function test_expired_battle_does_not_award_and_finished_receipt_still_replays(): void
    {
        $t = $this->terminal(); $future = $this->rawBattle($t)->expires_at;
        DB::table('nl_battles')->where('id', $t['battle_id'])->update(['expires_at' => RewardClock::now()->subSecond()]);
        $before = $this->economy(); $this->deny(410, fn () => $this->service()->finish($this->player, $t['battle_id'], [])); $this->assertSame($before, $this->economy());
        DB::table('nl_battles')->where('id', $t['battle_id'])->update(['expires_at' => $future]); $this->service()->finish($this->player, $t['battle_id'], []);
        DB::table('nl_battles')->where('id', $t['battle_id'])->update(['expires_at' => RewardClock::now()->subSecond()]);
        $this->assertTrue($this->service()->finish($this->player, $t['battle_id'], [])['replayed']);
    }
    public function test_owner_role_feature_and_http_authentication_boundaries_are_enforced(): void
    {
        $t = $this->start(); $other = Fixture::actor(); $this->deny(404, fn () => $this->service()->get($other, $t['battle_id']));
        $this->deny(403, fn () => app(TrialRules::class)->setActive($this->player, $this->rule, false));
        $this->getJson('/evaluation/v1/trials/definitions')->assertUnauthorized();
        $this->actingAs($this->player)->withSession(['nl_gateway_auth' => GatewayAccess::stamp($this->player)])->getJson('/evaluation/v1/trials/definitions')->assertOk();
        config(['mirror_trials.enabled' => false]); $this->getJson('/evaluation/v1/trials/definitions')->assertNotFound();
        config(['mirror_trials.enabled' => true]); DB::table('users')->where('id', $this->player->id)->update(['account_type' => 99]);
        $this->deny(403, fn () => $this->service()->get($this->player, $t['battle_id']));
    }
    public function test_admin_form_creates_inactive_then_explicitly_activates_and_pauses(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin')); $this->actingAs($this->staff);
        $p = Livewire::test(App\Filament\Pages\MirrorTrials::class)->set('trialSlug', 'qa-trial-form-'.bin2hex(random_bytes(4)))
            ->set('trialTitle', 'QA form trial')->set('rewardGold', '25')->set('rewardXp', '120')->call('createTrial')->assertHasNoErrors();
        $id = (int) $p->get('createdId'); $this->assertGreaterThan(0, $id);
        $p->call('activate', $id)->assertHasNoErrors(); $p->call('pause', $id)->assertHasNoErrors();
        $p->call('createTrial')->assertSet('createdId', (string) $id);
        $this->actingAs($this->player)->get('/admin/mirror-trials')->assertForbidden();
    }
}
