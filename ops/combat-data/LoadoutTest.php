<?php

use App\Models\User;
use App\Services\Combat\CombatLoadout;
use App\Support\Economy\StateRevision;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use NinjaLost\QA\Fixture;
use NinjaLost\QA\LoadoutFixture;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
require_once __DIR__.'/LoadoutFixture.php';

final class LoadoutTest extends TestCase
{
    use DatabaseTransactions;
    private User $admin;
    private User $actor;
    private App\Models\Character $character;
    private array $skill;
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $app;
    }
    protected function setUp(): void
    {
        parent::setUp(); Fixture::guard();
        $this->admin = Fixture::actor(99); $this->actor = Fixture::actor();
        $this->character = LoadoutFixture::character($this->actor); $this->skill = LoadoutFixture::skill($this->admin, $this->character);
    }
    private function prepare(): array { return app(CombatLoadout::class)->prepare($this->actor, $this->character->id); }
    private function reject(callable $action): void
    {
        $caught = null; try { $action(); } catch (ValidationException $e) { $caught = $e; }
        $this->assertInstanceOf(ValidationException::class, $caught);
    }
    private function deny(int $status, callable $action): void
    {
        $caught = null; try { $action(); } catch (HttpExceptionInterface $e) { $caught = $e; }
        $this->assertInstanceOf(HttpExceptionInterface::class, $caught); $this->assertSame($status, $caught->getStatusCode());
    }
    public function test_snapshot_is_read_from_database_without_currency_or_credential_fields(): void
    {
        $s = $this->prepare(); $this->assertSame($this->actor->id, $s['user_id']); $this->assertSame($this->character->id, $s['character']['id']);
        $this->assertSame(1, $s['revision']); $this->assertSame(10, $s['character']['level']);
        $this->assertSame($this->skill['id'], $s['character']['equipment_skills']);
        $this->assertSame('g8f-self-authored-v1', $s['skills'][0]['source_revision']);
        foreach (['password', 'tokens', 'email', 'gold', 'xp', 'won', 'rewards'] as $key) {
            $this->assertArrayNotHasKey($key, $s); $this->assertArrayNotHasKey($key, $s['character']);
        }
        $this->assertSame(1, DB::table('nl_claims')->count()); $this->assertSame(1, StateRevision::current($this->actor->id));
    }
    public function test_ownership_is_checked_not_a_caller_claimed_character_id(): void
    {
        $foreign = LoadoutFixture::character(Fixture::actor());
        $this->deny(404, fn () => app(CombatLoadout::class)->prepare($this->actor, $foreign->id));
    }
    public function test_current_role_reloaded_rejects_a_revoked_actor(): void
    {
        DB::table('users')->where('id', $this->actor->id)->update(['account_type' => 2]);
        $this->deny(403, fn () => $this->prepare());
    }
    public function test_equipped_but_unowned_skill_is_rejected(): void
    {
        DB::table('character_skills')->where('character_id', $this->character->id)->delete();
        $this->reject(fn () => $this->prepare());
    }
    public function test_ambiguous_ownership_and_equipped_duplicates_are_rejected(): void
    {
        $row = (array) DB::table('character_skills')->where('character_id', $this->character->id)->first(); unset($row['id']);
        DB::table('character_skills')->insert($row); $this->reject(fn () => $this->prepare());
        DB::table('characters')->where('id', $this->character->id)->update(['equipment_skills' => $this->skill['id'].','.$this->skill['id']]);
        $this->reject(fn () => $this->prepare());
    }
    public function test_disabled_registry_and_unknown_rights_are_rejected(): void
    {
        DB::table('nl_catalog')->where('id', $this->skill['registry'])->update(['enabled' => false]); $this->reject(fn () => $this->prepare());
        DB::table('nl_catalog')->where('id', $this->skill['registry'])->update(['enabled' => true, 'rights_basis' => 'unknown']); $this->reject(fn () => $this->prepare());
    }
    public function test_level_premium_and_element_requirements_are_not_bypassed(): void
    {
        DB::table('skills')->where('skill_id', $this->skill['id'])->update(['level' => 99]); $this->reject(fn () => $this->prepare());
        DB::table('skills')->where('skill_id', $this->skill['id'])->update(['level' => 1, 'premium' => true]); $this->reject(fn () => $this->prepare());
        DB::table('skills')->where('skill_id', $this->skill['id'])->update(['premium' => false, 'element' => 3]); $this->reject(fn () => $this->prepare());
    }
    public function test_gear_pet_and_talent_are_rejected_instead_of_silently_ignored(): void
    {
        foreach (['equipment_weapon', 'equipment_clothing', 'equipment_pet', 'talent_1', 'senjutsu_type'] as $field) {
            DB::table('characters')->where('id', $this->character->id)->update([$field => 1]); $this->reject(fn () => $this->prepare());
            DB::table('characters')->where('id', $this->character->id)->update([$field => 0]);
        }
    }
    public function test_unknown_skill_id_or_empty_slot_in_list_is_rejected(): void
    {
        foreach (['skill_missing', $this->skill['id'].',', '../skill_1'] as $skills) {
            DB::table('characters')->where('id', $this->character->id)->update(['equipment_skills' => $skills]); $this->reject(fn () => $this->prepare());
        }
    }
    public function test_weapon_only_empty_skill_set_can_be_read_without_fabricated_skills(): void
    {
        DB::table('characters')->where('id', $this->character->id)->update(['equipment_skills' => '']);
        $s = $this->prepare(); $this->assertSame([], $s['skills']); $this->assertSame('', $s['character']['equipment_skills']);
    }
    public function test_current_check_detects_stats_revision_and_payload_tampering(): void
    {
        $s = $this->prepare(); app(CombatLoadout::class)->assertCurrent($this->actor, $s);
        $bad = $s; $bad['character']['level'] = 50; $this->deny(409, fn () => app(CombatLoadout::class)->assertCurrent($this->actor, $bad));
        DB::table('characters')->where('id', $this->character->id)->update(['point_fire' => 1]);
        $this->deny(409, fn () => app(CombatLoadout::class)->assertCurrent($this->actor, $s));
        $this->assertSame(1, StateRevision::current($this->actor->id));
    }
    public function test_production_remains_closed(): void
    {
        app()->instance('env', 'production');
        try { $this->deny(403, fn () => $this->prepare()); } finally { app()->instance('env', 'testing'); }
    }
    public function test_database_owned_snapshots_drive_real_source_engine_without_reward_writes(): void
    {
        $enemy = Fixture::actor(); $other = LoadoutFixture::character($enemy); $otherSkill = LoadoutFixture::skill($this->admin, $other);
        $first = $this->prepare(); $second = app(CombatLoadout::class)->prepare($enemy, $other->id);
        app(CombatLoadout::class)->assertCurrent($this->actor, $first); app(CombatLoadout::class)->assertCurrent($enemy, $second);
        $before = ['users' => DB::table('users')->sum('tokens'), 'gold' => DB::table('characters')->sum('gold'), 'claims' => DB::table('nl_claims')->count(), 'ledger' => DB::table('nl_reward_ledger')->count(), 'revision' => StateRevision::current($this->actor->id)];
        $bundle = ['snapshots' => [$first, $second], 'revision' => 'g8f-self-authored-v1', 'skills' => [$this->skill['rule'], $otherSkill['rule']],
            'effects' => [['skill_id' => $this->skill['id'], 'skill_effect' => []], ['skill_id' => $otherSkill['id'], 'skill_effect' => []]]];
        $process = new Process(['node', base_path('ops/combat-data/bound-duel.cjs')], base_path());
        $process->setInput(json_encode($bundle, JSON_THROW_ON_ERROR)); $process->setTimeout(8); $process->mustRun();
        $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame([$this->character->id, $other->id], $result['participant_ids']);
        $this->assertContains($result['winner_id'], $result['participant_ids']);
        $this->assertSame(0, min($result['hp'])); $this->assertGreaterThan(0, max($result['hp']));
        $this->assertFalse($result['rewards_issued']); $this->assertSame(0, $result['legacy_db_calls']); $this->assertSame(1, $result['terminals_per_participant']);
        $this->assertGreaterThan(1, $result['actions']); $this->assertSame([460, 460], $result['cp']);
        $after = ['users' => DB::table('users')->sum('tokens'), 'gold' => DB::table('characters')->sum('gold'), 'claims' => DB::table('nl_claims')->count(), 'ledger' => DB::table('nl_reward_ledger')->count(), 'revision' => StateRevision::current($this->actor->id)];
        $this->assertSame($before, $after);
    }
}
