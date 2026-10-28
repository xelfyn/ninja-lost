<?php

use App\Content\EffectRegistry;
use App\Content\SkillBlueprint;
use App\Models\User;
use App\Services\Combat\CombatLoadout;
use App\Services\Content\PublishedLoadout;
use App\Services\Content\SkillStudio;
use App\Services\Rewards\StaffGrantService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use NinjaLost\QA\Fixture;
use NinjaLost\QA\LoadoutFixture;
use NinjaLost\QA\StudioFixture;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
require_once __DIR__.'/Fixture.php';

final class StudioTest extends TestCase
{
    use DatabaseTransactions;
    private User $admin;
    private SkillStudio $studio;
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $app;
    }
    protected function setUp(): void
    {
        parent::setUp(); Fixture::guard(); $this->admin = Fixture::actor(99); $this->studio = app(SkillStudio::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
    private function reject(callable $fn): void
    {
        $caught = null; try { $fn(); } catch (ValidationException $e) { $caught = $e; }
        $this->assertInstanceOf(ValidationException::class, $caught);
    }
    private function deny(int $code, callable $fn): void
    {
        $caught = null; try { $fn(); } catch (HttpExceptionInterface $e) { $caught = $e; }
        $this->assertInstanceOf(HttpExceptionInterface::class, $caught); $this->assertSame($code, $caught->getStatusCode());
    }
    public function test_zero_cost_cooldown_and_zero_chance_survive_compilation(): void
    {
        $d = StudioFixture::definition(['effects' => [['code' => 'stun', 'chance' => 0, 'duration' => 1, 'amount' => 0]]]);
        $p = SkillBlueprint::compile($d, (string) Str::uuid());
        $this->assertSame(0, $p['skills'][0]['cp_cost']); $this->assertSame(0, $p['skills'][0]['cooldown']);
        $this->assertSame(0, $p['effects'][0]['skill_effect'][0]['chance']); $this->assertFalse($p['client_ready']);
        $this->assertStringContainsString('peluang 0%', $p['tooltip']);
    }
    public function test_unknown_fields_effects_code_and_wrong_units_fail_closed(): void
    {
        foreach ([['command' => 'anything'], ['effects' => [['code' => 'agility_increase', 'chance' => 100, 'duration' => 1, 'amount' => 15]]],
            ['effects' => [['code' => 'stun', 'chance' => 100, 'duration' => 1, 'amount' => 15]]],
            ['effects' => [['code' => 'heal', 'chance' => 100, 'duration' => 2, 'amount' => 10]]],
            ['effects' => [['code' => 'burn', 'chance' => 101, 'duration' => 2, 'amount' => 10]]],
            ['effects' => [['code' => 'burn', 'chance' => 100, 'duration' => 2, 'amount' => 10, 'target' => 'self']]],
            ['damage' => 0], ['damage' => 1.5], ['cp_cost' => -1], ['premium' => 'false'], ['element' => 11],
            ['id' => 'skill_existing'], ['animation_key' => '../foreign.swf'], ['hit_frames' => [2]],
            ['animation_key' => 'skill_1', 'hit_frames' => [2, 3]]] as $changes) {
            $this->reject(fn () => SkillBlueprint::normalize(StudioFixture::definition($changes)));
        }
        $this->assertSame(0, DB::table('nl_studio_drafts')->count());
    }
    public function test_duplicate_effects_are_rejected_not_silently_merged(): void
    {
        $e = ['code' => 'burn', 'chance' => 100, 'duration' => 2, 'amount' => 10];
        $this->reject(fn () => SkillBlueprint::normalize(StudioFixture::definition(['effects' => [$e, $e]])));
    }
    public function test_tooltip_is_derived_and_lore_does_not_change_mechanics(): void
    {
        $d = StudioFixture::definition(['lore' => 'Claims unlimited agility; this is story only.', 'effects' => [['code' => 'burn', 'chance' => 30, 'duration' => 2, 'amount' => 10]]]);
        $p = SkillBlueprint::compile($d, (string) Str::uuid());
        $this->assertStringNotContainsString('agility', $p['tooltip']); $this->assertStringContainsString('peluang 30%', $p['tooltip']);
        $this->assertSame('enemy', $p['effects'][0]['skill_effect'][0]['target']);
        $this->assertSame('percent', $p['effects'][0]['skill_effect'][0]['calc_type']);
        $this->assertSame('reference_only_not_client_verified', $p['visual']['status']);
    }
    public function test_draft_has_no_live_definition_and_publish_has_no_activation_side_effect(): void
    {
        $d = StudioFixture::definition(); $draft = $this->studio->save($this->admin, $d);
        $this->assertSame(1, $draft['revision']); $this->assertDatabaseMissing('skills', ['skill_id' => $d['id']]);
        $r = $this->studio->publish($this->admin, $draft['id'], 1, $draft['hash']);
        $this->assertDatabaseMissing('skills', ['skill_id' => $d['id']]); $this->assertSame(0, DB::table('nl_studio_heads')->count());
        $this->assertSame(0, DB::table('nl_claims')->count());
        $again = $this->studio->publish($this->admin, $draft['id'], 1, $draft['hash']);
        $this->assertSame($r['id'], $again['id']); $this->assertTrue($again['replayed']); $this->assertSame(1, DB::table('nl_studio_releases')->count());
    }
    public function test_draft_compare_and_swap_rejects_other_session_changes(): void
    {
        $d = StudioFixture::definition(); $saved = $this->studio->save($this->admin, $d);
        $same = $this->studio->save($this->admin, $d, $saved['id'], 1); $this->assertTrue($same['unchanged']);
        $second = $this->studio->save($this->admin, array_replace($d, ['damage' => 90]), $saved['id'], 1); $this->assertSame(2, $second['revision']);
        $this->deny(409, fn () => $this->studio->save($this->admin, $d, $saved['id'], 1));
        $this->deny(409, fn () => $this->studio->publish($this->admin, $saved['id'], 1, $saved['hash']));
        $this->deny(409, fn () => $this->studio->publish($this->admin, $saved['id'], 2, $saved['hash']));
        $this->reject(fn () => $this->studio->save($this->admin, array_replace($d, ['id' => 'skill_nl_newkey']), $saved['id'], 2));
    }
    public function test_activation_updates_existing_backend_and_preserves_issued_supply_on_rollback(): void
    {
        $created = StudioFixture::release($this->admin, StudioFixture::definition(), true); $d = $created['definition']; $first = $created['release']['id'];
        $row = DB::table('nl_studio_drafts')->where('id', $created['draft']['id'])->first();
        $this->assertDatabaseHas('skills', ['skill_id' => $d['id'], 'name' => $d['name'], 'element' => 2]);
        $this->assertDatabaseHas('nl_catalog', ['id' => $row->catalog_id, 'enabled' => true, 'source_revision' => 'nl-studio:'.$first]);
        $player = Fixture::actor(); $char = LoadoutFixture::character($player);
        app(StaffGrantService::class)->grant($this->admin, $char->id, [['type' => 'skill', 'key' => $d['id'], 'quantity' => 1]], 'QA studio grant', (string) Str::uuid());
        DB::table('characters')->where('id', $char->id)->update(['equipment_skills' => $d['id']]);
        $old = app(PublishedLoadout::class)->prepare($player, $char->id);
        $s2 = $this->studio->save($this->admin, array_replace($d, ['damage' => 150]), $row->id, 1);
        $r2 = $this->studio->publish($this->admin, $row->id, 2, $s2['hash']);
        $this->deny(409, fn () => $this->studio->activate($this->admin, $r2['id'], null));
        $this->studio->activate($this->admin, $r2['id'], $first);
        $new = app(PublishedLoadout::class)->prepare($player, $char->id);
        $this->assertNotSame($old['snapshot']['skills'][0]['source_revision'], $new['snapshot']['skills'][0]['source_revision']);
        $this->deny(409, fn () => app(CombatLoadout::class)->assertCurrent($player, $old['snapshot']));
        $this->assertSame(80, json_decode($old['releases'][0]['payload'], true)['skills'][0]['damage']);
        $this->assertSame(150, json_decode($new['releases'][0]['payload'], true)['skills'][0]['damage']);
        $this->studio->activate($this->admin, $first, $r2['id']);
        $this->assertSame(1, (int) DB::table('nl_catalog')->where('id', $row->catalog_id)->value('issued'));
        $this->assertSame(1, DB::table('character_skills')->where('character_id', $char->id)->count());
        $this->assertSame(1, DB::table('nl_claims')->count()); $this->assertSame(1, DB::table('nl_reward_ledger')->count());
        $retry = $this->studio->activate($this->admin, $first, $r2['id']); $this->assertTrue($retry['replayed']);
        $this->assertSame(2, DB::table('nl_studio_releases')->count());
    }
    public function test_supply_cap_cannot_be_reset_by_new_version(): void
    {
        $c = StudioFixture::release($this->admin, StudioFixture::definition(), true);
        $s = $this->studio->save($this->admin, array_replace($c['definition'], ['supply_limit' => null]), $c['draft']['id'], 1);
        $r = $this->studio->publish($this->admin, $s['id'], 2, $s['hash']);
        $this->reject(fn () => $this->studio->activate($this->admin, $r['id'], $c['release']['id']));
        $this->assertDatabaseHas('nl_studio_heads', ['draft_id' => $s['id'], 'release_id' => $c['release']['id']]);
        $this->assertDatabaseHas('nl_catalog', ['content_key' => $c['definition']['id'], 'supply_limit' => 20]);
    }
    public function test_generic_metadata_edit_is_detected_instead_of_overwritten(): void
    {
        $c = StudioFixture::release($this->admin, StudioFixture::definition(), true);
        DB::table('skills')->where('skill_id', $c['definition']['id'])->update(['level' => 99]);
        $this->reject(fn () => $this->studio->activate($this->admin, $c['release']['id'], $c['release']['id']));
        $this->assertDatabaseHas('skills', ['skill_id' => $c['definition']['id'], 'level' => 99]);
    }
    public function test_existing_content_and_orphan_ownership_cannot_be_taken_over(): void
    {
        $d = StudioFixture::definition(); DB::table('skills')->insert(['skill_id' => $d['id'], 'name' => 'Existing', 'created_at' => now(), 'updated_at' => now()]);
        $this->reject(fn () => $this->studio->save($this->admin, $d));
        $player = Fixture::actor(); $character = Fixture::character($player); $other = StudioFixture::definition();
        DB::table('character_skills')->insert(['character_id' => $character->id, 'skill_id' => $other['id']]);
        $this->reject(fn () => $this->studio->save($this->admin, $other));
        $this->assertSame(0, DB::table('nl_studio_drafts')->count());
    }
    public function test_release_checksum_and_compiler_identity_are_verified(): void
    {
        $c = StudioFixture::release($this->admin, StudioFixture::definition());
        $row = DB::table('nl_studio_releases')->where('id', $c['release']['id'])->first();
        DB::table('nl_studio_releases')->where('id', $row->id)->update(['payload' => $row->payload.' ']);
        $this->reject(fn () => $this->studio->activate($this->admin, $row->id, null));
        $data = json_decode($row->payload, true); $data['skills'][0]['damage'] = 999;
        $bytes = SkillBlueprint::json($data);
        DB::table('nl_studio_releases')->where('id', $row->id)->update(['payload' => $bytes, 'payload_hash' => hash('sha256', $bytes)]);
        $this->reject(fn () => $this->studio->activate($this->admin, $row->id, null));
        $this->assertSame(0, DB::table('nl_studio_heads')->count()); $this->assertSame(0, DB::table('skills')->count());
    }
    public function test_failure_after_projection_and_head_update_rolls_back_everything(): void
    {
        $c = StudioFixture::release($this->admin, StudioFixture::definition()); $armed = true;
        DB::listen(function ($query) use (&$armed) {
            if ($armed && str_starts_with($query->sql, 'insert into `nl_studio_heads`')) throw new RuntimeException('qa-after-head');
        });
        $caught = null;
        try { $this->studio->activate($this->admin, $c['release']['id'], null); } catch (RuntimeException $e) { $caught = $e; } finally { $armed = false; }
        $this->assertSame('qa-after-head', $caught?->getMessage());
        $this->assertSame(0, DB::table('nl_studio_heads')->count());
        $this->assertDatabaseMissing('skills', ['skill_id' => $c['definition']['id']]);
        $this->assertDatabaseMissing('nl_catalog', ['content_key' => $c['definition']['id']]);
        $this->assertNull(DB::table('nl_studio_drafts')->where('id', $c['draft']['id'])->value('catalog_id'));
        $this->assertSame(1, DB::table('nl_studio_releases')->count());
    }
    public function test_role_is_reloaded_for_every_write_and_production_is_closed(): void
    {
        $c = StudioFixture::release($this->admin, StudioFixture::definition());
        DB::table('users')->where('id', $this->admin->id)->update(['account_type' => 0]);
        $this->deny(403, fn () => $this->studio->save($this->admin, StudioFixture::definition()));
        $this->deny(403, fn () => $this->studio->publish($this->admin, $c['draft']['id'], 1, $c['draft']['hash']));
        $this->deny(403, fn () => $this->studio->activate($this->admin, $c['release']['id'], null));
        DB::table('users')->where('id', $this->admin->id)->update(['account_type' => 99]);
        app()->instance('env', 'production');
        try { $this->deny(403, fn () => $this->studio->activate($this->admin, $c['release']['id'], null)); }
        finally { app()->instance('env', 'testing'); }
    }
    public function test_guest_and_player_cannot_open_or_hydrate_studio(): void
    {
        $this->get('/admin/content-studio')->assertRedirect('/admin/login');
        $this->actingAs(Fixture::actor())->get('/admin/content-studio')->assertForbidden();
        Livewire::test(App\Filament\Pages\ContentStudio::class)->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/content-studio')->assertOk()->assertSee('Kamus mekanik profil awal');
    }
    public function test_form_save_publish_activate_and_duplicate_use_real_service(): void
    {
        $this->actingAs($this->admin); $d = StudioFixture::definition(); $d['hit_frames'] = '';
        $page = Livewire::test(App\Filament\Pages\ContentStudio::class)->set('data', $d)->call('saveDraft')->assertHasNoErrors();
        $id = $page->get('draftId'); $this->assertGreaterThan(0, $id);
        $page->call('publishDraft')->assertHasNoErrors(); $release = $page->get('publishedId'); $this->assertNotEmpty($release);
        $page->call('activateRelease', $release)->assertHasNoErrors()->assertSet('currentHead', $release);
        $this->assertDatabaseHas('skills', ['skill_id' => $d['id']]);
        $page->call('duplicateDraft')->assertSet('draftId', null); $this->assertNotSame($d['id'], $page->get('data.id'));
        $this->assertSame(1, DB::table('nl_studio_drafts')->count());
    }
    public function test_unsaved_form_changes_cannot_be_published(): void
    {
        $this->actingAs($this->admin); $d = StudioFixture::definition(); $d['hit_frames'] = '';
        $page = Livewire::test(App\Filament\Pages\ContentStudio::class)->set('data', $d)->call('saveDraft')->assertHasNoErrors();
        $page->set('data.damage', 99)->call('publishDraft')->assertStatus(409);
        $this->assertSame(0, DB::table('nl_studio_releases')->count());
    }
    public function test_editor_reference_fields_are_locked(): void
    {
        $this->actingAs($this->admin);
        $page = Livewire::test(App\Filament\Pages\ContentStudio::class);
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $page->set('draftRevision', 999);
    }
    public function test_destructive_migration_rollback_refuses(): void
    {
        $migration = require base_path('database/migrations/2026_10_28_100000_create_content_studio.php');
        $this->expectException(RuntimeException::class); $migration->down();
    }
    public function test_published_owned_content_is_executed_by_existing_engine(): void
    {
        $effects = [
            ['code' => 'stun', 'chance' => 100, 'duration' => 1, 'amount' => 0],
            ['code' => 'burn', 'chance' => 100, 'duration' => 2, 'amount' => 10],
            ['code' => 'heal', 'chance' => 100, 'duration' => 0, 'amount' => 10],
            ['code' => 'purify', 'chance' => 100, 'duration' => 0, 'amount' => 0],
            ['code' => 'disperse', 'chance' => 100, 'duration' => 0, 'amount' => 0],
        ];
        $c = StudioFixture::release($this->admin, StudioFixture::definition(['cp_cost' => 12, 'cooldown' => 3, 'effects' => $effects]), true);
        $bundles = [];
        for ($i = 0; $i < 2; $i++) {
            $u = Fixture::actor(); $char = LoadoutFixture::character($u);
            app(StaffGrantService::class)->grant($this->admin, $char->id, [['type' => 'skill', 'key' => $c['definition']['id'], 'quantity' => 1]], 'QA authored skill ownership', (string) Str::uuid());
            DB::table('characters')->where('id', $char->id)->update(['equipment_skills' => $c['definition']['id'], 'point_wind' => $i === 0 ? 1 : 0]);
            $bundles[] = app(PublishedLoadout::class)->prepare($u, $char->id);
        }
        $before = ['claims' => DB::table('nl_claims')->count(), 'ledger' => DB::table('nl_reward_ledger')->count(), 'tokens' => DB::table('users')->sum('tokens')];
        $process = new Process(['node', base_path('ops/content-studio/engine.cjs')], base_path());
        $process->setInput(json_encode(['bundles' => $bundles], JSON_THROW_ON_ERROR)); $process->setTimeout(8); $process->mustRun();
        $r = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(74, $r['direct_damage']); $this->assertSame(46, $r['burn_damage']); $this->assertSame(46, $r['healing']);
        $this->assertSame(12, $r['cp_spent']); $this->assertSame(3, $r['cooldown_after_action']);
        $this->assertTrue($r['stun_skip']); $this->assertTrue($r['purified']); $this->assertTrue($r['dispersed']);
        $this->assertSame(0, $r['legacy_db_calls']); $this->assertFalse($r['native_client_verified']);
        $this->assertSame($before, ['claims' => DB::table('nl_claims')->count(), 'ledger' => DB::table('nl_reward_ledger')->count(), 'tokens' => DB::table('users')->sum('tokens')]);
    }
}
