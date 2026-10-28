<?php

use App\Evaluation\GatewayAccess;
use App\Evaluation\TrainingService;
use App\Models\User;
use App\Services\Content\EquipmentStudio;
use App\Services\Rewards\ClientStateService;
use App\Services\Rewards\StaffGrantService;
use App\Support\Economy\StateRevision;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use NinjaLost\QA\EquipmentFixture as EF;
use NinjaLost\QA\Fixture;
use NinjaLost\QA\LoadoutFixture;
use NinjaLost\QA\StudioFixture;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
require_once __DIR__.'/../equipment-studio/Fixture.php';
require_once __DIR__.'/../content-studio/Fixture.php';

final class TrainingTest extends TestCase
{
    use DatabaseTransactions;
    private User $staff;
    private User $player;
    private App\Models\Character $character;
    private array $gear;
    private array $release;
    private string $skill;
    public function createApplication() { $app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $app; }
    protected function setUp(): void
    {
        parent::setUp(); Fixture::guard(); $this->staff = Fixture::actor(99); $this->player = Fixture::actor(); $this->character = LoadoutFixture::character($this->player);
        $this->gear = EF::definition(); $this->release = EF::release($this->staff, $this->gear);
        app(EquipmentStudio::class)->activate($this->staff, $this->release['release']['id'], null);
        EF::grantEquip($this->staff, $this->player, $this->character, $this->gear);
        $d = StudioFixture::definition(['cp_cost' => 12, 'cooldown' => 3, 'effects' => [['code' => 'stun', 'chance' => 100, 'duration' => 1, 'amount' => 0]]]);
        StudioFixture::release($this->staff, $d, true); $this->skill = $d['id'];
        app(StaffGrantService::class)->grant($this->staff, $this->character->id, [['type' => 'skill', 'key' => $this->skill, 'quantity' => 1]], 'Training fixture skill', (string) Str::uuid());
        $this->save([$this->skill]);
    }
    private function service(): TrainingService { return app(TrainingService::class); }
    private function save(array $skills, ?array $equipment = null): array
    {
        return $this->service()->saveLoadout($this->player, $this->character->id, ['revision' => StateRevision::current($this->player->id),
            'equipment' => $equipment ?? array_column($this->gear['pieces'], 'id', 'slot'), 'skills' => $skills]);
    }
    private function startInput(): array { return ['character_id' => $this->character->id, 'revision' => StateRevision::current($this->player->id), 'request_key' => (string) Str::uuid()]; }
    private function start(): array { return $this->service()->start($this->player, $this->startInput())['run']; }
    private function command(array $run, string $action = 'weapon'): array { return ['revision' => $run['revision'], 'request_key' => (string) Str::uuid(), 'action' => $action]; }
    private function denied(int $status, callable $callback): void
    {
        $error = null; try { $callback(); } catch (HttpExceptionInterface $e) { $error = $e; }
        $this->assertNotNull($error); $this->assertSame($status, $error->getStatusCode());
    }
    private function invalid(callable $callback): void
    {
        $error = null; try { $callback(); } catch (ValidationException $e) { $error = $e; }
        $this->assertInstanceOf(ValidationException::class, $error);
    }
    private function economy(): array
    {
        return ['tokens' => DB::table('users')->where('id', $this->player->id)->value('tokens'), 'character' => (array) DB::table('characters')->where('id', $this->character->id)->first(['gold', 'xp', 'level']),
            'claims' => DB::table('nl_claims')->count(), 'ledger' => DB::table('nl_reward_ledger')->count(), 'revision' => StateRevision::current($this->player->id),
            'items' => DB::table('character_items')->where('character_id', $this->character->id)->orderBy('id')->get()->toJson()];
    }
    public function test_owned_studio_skill_and_gear_reach_actual_engine_without_economic_writes(): void
    {
        $before = $this->economy(); $run = $this->start();
        $this->assertSame('active', $run['status']); $this->assertSame(560, $run['state']['player']['max_hp']);
        $this->assertSame(44, $run['state']['player']['agility']); $this->assertSame(84, $run['state']['player']['attack']);
        $this->assertCount(1, $run['state']['active_sets']); $this->assertSame($this->skill, $run['state']['skills'][0]['id']);
        $this->assertSame($before, $this->economy()); $this->assertArrayNotHasKey('seed', $run); $this->assertArrayNotHasKey('bundle', $run);
    }
    public function test_start_retry_retains_one_snapshot_and_changed_intent_is_conflict(): void
    {
        $input = $this->startInput(); $a = $this->service()->start($this->player, $input); $b = $this->service()->start($this->player, $input);
        $this->assertSame($a['run'], $b['run']); $this->assertTrue($b['replayed']); $this->assertSame(1, DB::table('nl_training_runs')->count());
        $input['revision']++; $this->denied(409, fn () => $this->service()->start($this->player, $input));
        $this->denied(409, fn () => $this->start());
    }
    public function test_skill_changes_real_cp_and_cooldown_and_retry_does_not_repeat_it(): void
    {
        $run = $this->start(); $command = $this->command($run, 'skill') + ['skill_id' => $this->skill];
        $a = $this->service()->action($this->player, $run['id'], $command); $b = $this->service()->action($this->player, $run['id'], $command);
        $this->assertSame(1, $a['run']['revision']); $this->assertLessThan($run['state']['player']['cp'], $a['run']['state']['player']['cp']);
        $this->assertGreaterThan(0, $a['run']['state']['skills'][0]['cooldown']); $this->assertSame($a['run'], $b['run']);
        $this->assertTrue($b['replayed']); $this->assertSame(1, DB::table('nl_training_actions')->count());
        $this->denied(422, fn () => $this->service()->action($this->player, $run['id'], $this->command($a['run'], 'skill') + ['skill_id' => $this->skill]));
    }
    public function test_stale_or_modified_action_key_cannot_change_current_state(): void
    {
        $run = $this->start(); $command = $this->command($run); $a = $this->service()->action($this->player, $run['id'], $command);
        $this->denied(409, fn () => $this->service()->action($this->player, $run['id'], $this->command($run)));
        $command['action'] = 'charge'; $this->denied(409, fn () => $this->service()->action($this->player, $run['id'], $command));
        $this->assertSame($a['run'], $this->service()->get($this->player, $run['id']));
    }
    public function test_client_verdict_stats_seed_and_transport_callbacks_are_rejected(): void
    {
        $run = $this->start();
        foreach (['winner', 'damage', 'seed', 'snapshot'] as $field) $this->invalid(fn () => $this->service()->action($this->player, $run['id'], $this->command($run) + [$field => 1]));
        $this->invalid(fn () => $this->service()->action($this->player, $run['id'], $this->command($run, 'finished')));
        $this->denied(422, fn () => $this->service()->action($this->player, $run['id'], $this->command($run, 'skill') + ['skill_id' => 'skill_nl_unowned']));
        $this->assertSame(0, DB::table('nl_training_actions')->count());
    }
    public function test_invalid_loadout_rolls_back_equipment_skill_selection_and_revision(): void
    {
        $before = $this->service()->loadout($this->player, $this->character->id); $economy = $this->economy();
        $this->invalid(fn () => $this->save(['skill_nl_not_owned'], ['weapon' => '', 'back' => '']));
        $this->assertSame($before, $this->service()->loadout($this->player, $this->character->id)); $this->assertSame($economy, $this->economy());
        $this->invalid(fn () => $this->save([$this->skill, $this->skill]));
    }
    public function test_active_training_is_pinned_across_unequip_and_new_studio_release(): void
    {
        $run = $this->start(); $this->save([], ['weapon' => '', 'back' => '', 'clothing' => '', 'accessory' => '']);
        $d = $this->gear; $d['pieces'][0]['modifiers'][1]['amount'] = 30;
        $studio = app(EquipmentStudio::class); $draft = $studio->save($this->staff, $d, $this->release['draft']['id'], $this->release['draft']['revision']);
        $release = $studio->publish($this->staff, $draft['id'], $draft['revision'], $draft['hash']); $studio->activate($this->staff, $release['id'], $this->release['release']['id']);
        $after = $this->service()->action($this->player, $run['id'], $this->command($run, 'charge'))['run'];
        $this->assertSame(560, $after['state']['player']['max_hp']); $this->assertSame(84, $after['state']['player']['attack']);
        $this->service()->action($this->player, $run['id'], $this->command($after, 'run'));
        $new = $this->start(); $this->assertSame(460, $new['state']['player']['max_hp']); $this->assertSame(50, $new['state']['player']['attack']); $this->assertSame([], $new['state']['skills']);
    }
    public function test_terminal_result_persists_without_mission_settlement_or_rewards(): void
    {
        $before = $this->economy(); $run = $this->start();
        $terminal = $this->service()->action($this->player, $run['id'], $this->command($run, 'run'))['run'];
        $this->assertSame('ended', $terminal['status']); $this->assertFalse($terminal['state']['outcome']['won']);
        $this->assertSame($terminal, $this->service()->get($this->player, $run['id'])); $this->assertSame($before, $this->economy());
        $this->denied(409, fn () => $this->service()->action($this->player, $run['id'], $this->command($terminal)));
    }
    public function test_foreign_account_and_revoked_player_cannot_access_training(): void
    {
        $run = $this->start(); $other = Fixture::actor();
        $this->denied(404, fn () => $this->service()->get($other, $run['id']));
        $this->denied(404, fn () => $this->service()->start($other, $this->startInput()));
        DB::table('users')->where('id', $this->player->id)->update(['account_type' => 99]);
        $this->denied(403, fn () => $this->service()->get($this->player, $run['id']));
    }
    public function test_expired_session_does_not_advance_but_receipt_retry_remains_safe(): void
    {
        $run = $this->start(); $command = $this->command($run); $a = $this->service()->action($this->player, $run['id'], $command);
        DB::table('nl_training_runs')->where('id', $run['id'])->update(['expires_at' => now()->subMinute()]);
        $this->assertSame('expired', $this->service()->get($this->player, $run['id'])['status']);
        $this->denied(410, fn () => $this->service()->action($this->player, $run['id'], $this->command($a['run'])));
        $this->assertTrue($this->service()->action($this->player, $run['id'], $command)['replayed']);
    }
    public function test_source_contract_or_snapshot_drift_refuses_continuation(): void
    {
        $run = $this->start(); $hash = DB::table('nl_training_runs')->where('id', $run['id'])->value('runtime_hash');
        DB::table('nl_training_runs')->where('id', $run['id'])->update(['runtime_hash' => str_repeat('0', 64)]);
        $this->denied(409, fn () => $this->service()->action($this->player, $run['id'], $this->command($run)));
        DB::table('nl_training_runs')->where('id', $run['id'])->update(['runtime_hash' => $hash, 'bundle' => '{}']);
        $this->denied(409, fn () => $this->service()->action($this->player, $run['id'], $this->command($run)));
        $this->assertSame(0, DB::table('nl_training_actions')->count());
    }
    public function test_late_failure_rolls_back_action_receipt_and_state_together(): void
    {
        $run = $this->start(); $armed = true;
        DB::listen(function ($query) use (&$armed) { if ($armed && str_starts_with($query->sql, 'update `nl_training_runs`')) { $armed = false; throw new RuntimeException('injected training failure'); } });
        $error = null; $command = $this->command($run);
        try { $this->service()->action($this->player, $run['id'], $command); } catch (RuntimeException $e) { $error = $e; } finally { $armed = false; }
        $this->assertNotNull($error); $this->assertSame(0, DB::table('nl_training_actions')->count());
        $this->assertSame($run, $this->service()->get($this->player, $run['id']));
        $this->assertSame(1, $this->service()->action($this->player, $run['id'], $command)['run']['revision']);
    }
    public function test_http_authentication_and_cached_route_gates_are_not_bypassed(): void
    {
        $url = '/evaluation/v1/training/characters/'.$this->character->id.'/loadout';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($this->player)->withSession(['nl_gateway_auth' => GatewayAccess::stamp($this->player)])->getJson($url)->assertOk()->assertJsonPath('character_id', $this->character->id);
        config(['training.enabled' => false]); $this->getJson($url)->assertNotFound();
        config(['training.enabled' => true]); $this->app->instance('env', 'production'); $this->getJson($url)->assertNotFound();
    }
    public function test_engine_failure_does_not_create_an_empty_battle(): void
    {
        config(['training.source' => '/nonexistent-training-source']);
        $this->denied(503, fn () => $this->start()); $this->assertSame(0, DB::table('nl_training_runs')->count());
    }
}
