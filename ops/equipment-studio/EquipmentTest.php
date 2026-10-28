<?php

use App\Content\EquipmentBlueprint as Blueprint;
use App\Content\SkillBlueprint;
use App\Filament\Pages\EquipmentStudio as Page;
use App\Models\User;
use App\Services\Combat\CombatLoadout;
use App\Services\Content\EquipmentStudio as Studio;
use App\Services\Content\PublishedLoadout;
use App\Services\Rewards\ClientStateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use NinjaLost\QA\EquipmentFixture as EF;
use NinjaLost\QA\Fixture;
use NinjaLost\QA\LoadoutFixture;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
require_once __DIR__.'/Fixture.php';

final class EquipmentTest extends TestCase
{
    use DatabaseTransactions;
    private User $staff;
    private User $player;
    private App\Models\Character $character;
    private array $definition;
    public function createApplication() { $app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $app; }
    protected function setUp(): void
    {
        parent::setUp(); Fixture::guard(); $this->staff = Fixture::actor(99); $this->player = Fixture::actor();
        $this->character = LoadoutFixture::character($this->player); $this->definition = EF::definition();
    }
    private function rejected(callable $f): void
    {
        $error = null; try { $f(); } catch (ValidationException $e) { $error = $e; } $this->assertInstanceOf(ValidationException::class, $error);
    }
    private function denied(int $status, callable $f): void
    {
        $error = null; try { $f(); } catch (HttpExceptionInterface $e) { $error = $e; } $this->assertNotNull($error); $this->assertSame($status, $error->getStatusCode());
    }
    private function active(): array
    {
        $r = EF::release($this->staff, $this->definition); app(Studio::class)->activate($this->staff, $r['release']['id'], null); return $r;
    }
    private function owned(): array { $r = $this->active(); EF::grantEquip($this->staff, $this->player, $this->character, $this->definition); return $r; }
    private function version(array $base, array $d): array
    {
        $draft = app(Studio::class)->save($this->staff, $d, $base['draft']['id'], $base['draft']['revision']);
        return ['draft' => $draft, 'release' => app(Studio::class)->publish($this->staff, $draft['id'], $draft['revision'], $draft['hash'])];
    }
    public function test_canonical_modifier_order_and_tooltip_are_derived(): void
    {
        $a = Blueprint::normalize($this->definition); $d = $this->definition; $d['pieces'] = array_reverse($d['pieces']); $d['pieces'][1]['modifiers'] = array_reverse($d['pieces'][1]['modifiers']);
        $this->assertSame($a, Blueprint::normalize($d)); $this->assertStringContainsString('2 bagian', Blueprint::tooltip($a));
        $d['lore'] = 'Different story'; $this->assertSame(Blueprint::tooltip($a), Blueprint::tooltip(Blueprint::normalize($d)));
        $this->assertSame(0, Blueprint::modifiers([['stat' => 'atk', 'unit' => 'flat', 'amount' => 0]])[0]['amount']);
    }
    public function test_unknown_mechanics_units_nonintegers_and_duplicate_stats_reject(): void
    {
        foreach ([['stat' => 'accuracy', 'unit' => 'flat', 'amount' => 15], ['stat' => 'critPct', 'unit' => 'percent', 'amount' => 15],
            ['stat' => 'atk', 'unit' => 'flat', 'amount' => true], ['stat' => 'atk', 'unit' => 'flat', 'amount' => 1.5],
            ['stat' => 'atk', 'unit' => 'flat', 'amount' => -1], ['stat' => 'atk', 'unit' => 'flat', 'amount' => 10001],
            ['stat' => 'atk', 'unit' => 'flat', 'amount' => 10, 'script' => 'ignored']] as $m) $this->rejected(fn () => Blueprint::modifiers([$m]));
        $m = ['stat' => 'atk', 'unit' => 'flat', 'amount' => 1]; $this->rejected(fn () => Blueprint::modifiers([$m, $m]));
    }
    public function test_wrong_slot_duplicate_members_and_unreachable_tiers_reject(): void
    {
        $d = $this->definition; $d['pieces'][0]['slot'] = 'back'; $this->rejected(fn () => Blueprint::normalize($d));
        $d = $this->definition; $d['pieces'][1]['id'] = $d['pieces'][0]['id']; $this->rejected(fn () => Blueprint::normalize($d));
        $d = $this->definition; $d['bonuses'][0]['required'] = 3; $this->rejected(fn () => Blueprint::normalize($d));
        $d = $this->definition; $d['bonuses'][] = $d['bonuses'][0]; $this->rejected(fn () => Blueprint::normalize($d));
        $d = $this->definition; $d['pieces'][0]['visual_key'] = '../evil.swf'; $this->rejected(fn () => Blueprint::normalize($d));
    }
    public function test_draft_and_publication_are_not_activation_or_grants(): void
    {
        $r = EF::release($this->staff, $this->definition); $this->assertSame(0, DB::table('items')->count()); $this->assertSame(0, DB::table('nl_catalog')->count());
        $this->assertSame(0, DB::table('nl_studio_heads')->count()); $this->assertSame(0, DB::table('nl_claims')->count());
        $p = app(Studio::class)->publish($this->staff, $r['draft']['id'], 1, $r['draft']['hash']);
        $this->assertSame($r['release']['id'], $p['id']); $this->assertTrue($p['replayed']); $this->assertSame(1, DB::table('nl_studio_releases')->count());
    }
    public function test_stale_edit_publish_and_activation_are_conflicts(): void
    {
        $r = $this->active(); $d = $this->definition; $d['name'] = 'Changed'; $v = $this->version($r, $d);
        $this->denied(409, fn () => app(Studio::class)->save($this->staff, $d, $r['draft']['id'], 1));
        $this->denied(409, fn () => app(Studio::class)->publish($this->staff, $r['draft']['id'], 1, $r['draft']['hash']));
        $this->denied(409, fn () => app(Studio::class)->activate($this->staff, $v['release']['id'], null));
        $this->assertSame($r['release']['id'], DB::table('nl_studio_heads')->value('release_id'));
    }
    public function test_activation_projects_all_pieces_and_rollback_keeps_inventory_supply_and_wallets(): void
    {
        $r = $this->owned(); $counts = [DB::table('nl_catalog')->sum('issued'), DB::table('character_items')->sum('quantity'), DB::table('nl_claims')->count(), DB::table('nl_reward_ledger')->count(), DB::table('users')->sum('tokens')];
        $d = $this->definition; $d['pieces'][0]['modifiers'][0]['amount'] = 25; $v = $this->version($r, $d);
        app(Studio::class)->activate($this->staff, $v['release']['id'], $r['release']['id']);
        app(Studio::class)->activate($this->staff, $r['release']['id'], $v['release']['id']);
        $this->assertSame($counts, [DB::table('nl_catalog')->sum('issued'), DB::table('character_items')->sum('quantity'), DB::table('nl_claims')->count(), DB::table('nl_reward_ledger')->count(), DB::table('users')->sum('tokens')]);
        $this->assertSame(2, DB::table('items')->count()); $this->assertSame(2, DB::table('nl_studio_equipment')->count());
        $this->assertTrue(app(Studio::class)->activate($this->staff, $r['release']['id'], null)['replayed']);
    }
    public function test_supply_cap_and_membership_cannot_change_on_existing_bundle(): void
    {
        $r = $this->active(); $d = $this->definition; $d['pieces'][0]['supply_limit'] = 30; $v = $this->version($r, $d);
        $this->rejected(fn () => app(Studio::class)->activate($this->staff, $v['release']['id'], $r['release']['id']));
        $d['pieces'][0]['supply_limit'] = 20; $d['pieces'][0]['id'] .= '_new'; $v2 = $this->version($v, $d);
        $this->rejected(fn () => app(Studio::class)->activate($this->staff, $v2['release']['id'], $r['release']['id']));
    }
    public function test_collision_and_projection_corruption_fail_without_overwrite(): void
    {
        $r = EF::release($this->staff, $this->definition);
        DB::table('items')->insert(['item_id' => $this->definition['pieces'][0]['id'], 'name' => 'Existing', 'category' => 'weapon']);
        $this->rejected(fn () => app(Studio::class)->activate($this->staff, $r['release']['id'], null));
        $this->assertSame(1, DB::table('items')->count()); $this->assertSame(0, DB::table('nl_studio_heads')->count());
        DB::table('items')->delete(); app(Studio::class)->activate($this->staff, $r['release']['id'], null);
        DB::table('items')->where('category', 'weapon')->update(['name' => 'External edit']);
        $this->rejected(fn () => app(Studio::class)->activate($this->staff, $r['release']['id'], null));
    }
    public function test_late_activation_failure_rolls_back_whole_bundle(): void
    {
        $r = EF::release($this->staff, $this->definition); $connection = DB::connection(); $fired = false;
        $connection->beforeExecuting(function ($sql) use (&$fired) { if (!$fired && str_contains($sql, 'insert into `nl_operations_audit`')) { $fired = true; throw new RuntimeException('Injected audit failure'); } });
        try { app(Studio::class)->activate($this->staff, $r['release']['id'], null); $this->fail('Failure expected'); } catch (RuntimeException $e) { $this->assertSame('Injected audit failure', $e->getMessage()); }
        $this->assertTrue($fired); $this->assertSame(0, DB::table('items')->count()); $this->assertSame(0, DB::table('nl_catalog')->count()); $this->assertSame(0, DB::table('nl_studio_equipment')->count()); $this->assertSame(0, DB::table('nl_studio_heads')->count());
    }
    public function test_missing_revoked_player_and_production_writes_are_denied(): void
    {
        $this->denied(403, fn () => app(Studio::class)->save($this->player, $this->definition));
        DB::table('users')->where('id', $this->staff->id)->update(['account_type' => 0]); $this->denied(403, fn () => app(Studio::class)->save($this->staff, $this->definition));
        DB::table('users')->where('id', $this->staff->id)->delete(); $this->denied(403, fn () => app(Studio::class)->save($this->staff, $this->definition));
        app()->instance('env', 'production'); try { $this->denied(403, fn () => app(Studio::class)->save($this->player, $this->definition)); } finally { app()->instance('env', 'testing'); }
    }
    public function test_equipped_unowned_disabled_wrong_level_and_foreign_character_are_denied(): void
    {
        $r = $this->owned(); $reader = app(CombatLoadout::class); $id = $this->definition['pieces'][0]['id'];
        $this->denied(404, fn () => $reader->prepareEquipped(Fixture::actor(), $this->character->id));
        DB::table('character_items')->where('item_id', $id)->update(['quantity' => 0]); $this->rejected(fn () => $reader->prepareEquipped($this->player, $this->character->id));
        DB::table('character_items')->where('item_id', $id)->update(['quantity' => 1]);
        DB::table('nl_catalog')->where('content_key', $id)->update(['enabled' => false]); $this->rejected(fn () => $reader->prepareEquipped($this->player, $this->character->id));
        DB::table('nl_catalog')->where('content_key', $id)->update(['enabled' => true]);
        $d = $this->definition; $d['pieces'][0]['level'] = 11; $v = $this->version($r, $d); app(Studio::class)->activate($this->staff, $v['release']['id'], $r['release']['id']);
        $this->rejected(fn () => $reader->prepareEquipped($this->player, $this->character->id));
    }
    public function test_snapshot_is_pinned_and_current_check_rejects_tampering_or_release_change(): void
    {
        $r = $this->owned(); $reader = app(CombatLoadout::class); $s = $reader->prepareEquipped($this->player, $this->character->id);
        $reader->assertEquippedCurrent($this->player, $s); $bad = $s; $bad['equipment']['equipped']['weapon'] = 'wpn_nl_fake';
        $this->denied(409, fn () => $reader->assertEquippedCurrent($this->player, $bad));
        $d = $this->definition; $d['pieces'][0]['modifiers'][0]['amount'] = 25; $v = $this->version($r, $d); app(Studio::class)->activate($this->staff, $v['release']['id'], $r['release']['id']);
        $this->denied(409, fn () => $reader->assertEquippedCurrent($this->player, $s));
        $this->assertSame($r['release']['id'], $s['equipment']['releases'][0]['id']);
    }
    public function test_pets_talents_and_senjutsu_still_reject_not_silently_ignore(): void
    {
        $this->owned();
        foreach (['equipment_pet', 'talent_1', 'senjutsu_type'] as $key) {
            DB::table('characters')->where('id', $this->character->id)->update([$key => 1]);
            $this->rejected(fn () => app(CombatLoadout::class)->prepareEquipped($this->player, $this->character->id));
            DB::table('characters')->where('id', $this->character->id)->update([$key => 0]);
        }
    }
    public function test_web_actions_and_namespace_separation(): void
    {
        $this->actingAs($this->staff);
        $page = Livewire::test(Page::class)->set('definition', $this->definition)->call('previewDefinition')->assertHasNoErrors()->call('saveDraft')->assertHasNoErrors();
        $id = $page->get('draftId'); $this->assertIsInt($id); $this->assertSame(0, DB::table('items')->count());
        $page->call('publishDraft')->assertHasNoErrors(); $release = $page->get('publishedId');
        $page->call('activateRelease', $release)->assertHasNoErrors(); $this->assertSame(2, DB::table('items')->count());
        Livewire::test(App\Filament\Pages\ContentStudio::class)->call('loadDraft', $id)->assertStatus(404);
        $page->call('duplicateDraft')->assertHasNoErrors(); $this->assertNull($page->get('draftId')); $this->assertNotSame($this->definition['id'], $page->get('definition.id'));
    }
    public function test_guest_player_and_locked_editor_references_are_protected(): void
    {
        $this->get('/admin/equipment-studio')->assertRedirect();
        $this->actingAs($this->player); Livewire::test(Page::class)->assertStatus(403);
        $this->actingAs($this->staff);
        $error = null; try { Livewire::test(Page::class)->set('draftId', 999); } catch (Throwable $e) { $error = $e; } $this->assertNotNull($error);
    }
    public function test_unsaved_changes_cannot_publish(): void
    {
        $this->actingAs($this->staff); $page = Livewire::test(Page::class)->set('definition', $this->definition)->call('saveDraft')->assertHasNoErrors();
        $page->set('definition.name', 'Unsaved')->call('publishDraft')->assertStatus(409); $this->assertSame(0, DB::table('nl_studio_releases')->count());
    }
    public function test_release_corruption_and_destructive_down_are_rejected(): void
    {
        $r = EF::release($this->staff, $this->definition); DB::table('nl_studio_releases')->where('id', $r['release']['id'])->update(['payload' => '{}']);
        $this->rejected(fn () => app(Studio::class)->activate($this->staff, $r['release']['id'], null));
        $m = require base_path('database/migrations/2026_10_28_110000_add_equipment_studio.php');
        $this->expectException(RuntimeException::class); $m->down();
    }
    public function test_database_owned_gear_changes_actual_engine_and_unequip_removes_set_bonus(): void
    {
        $this->owned(); $other = Fixture::actor(); $enemy = LoadoutFixture::character($other);
        $run = function () use ($other, $enemy) {
            $bundles = [app(PublishedLoadout::class)->prepareEquipped($this->player, $this->character->id), app(PublishedLoadout::class)->prepareEquipped($other, $enemy->id)];
            $p = new Process(['node', base_path('ops/equipment-studio/engine.cjs')], base_path()); $p->setInput(json_encode(['bundles' => $bundles], JSON_THROW_ON_ERROR)); $p->setTimeout(10); $p->mustRun();
            return json_decode($p->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        };
        $before = [DB::table('users')->sum('tokens'), DB::table('characters')->sum('gold'), DB::table('nl_claims')->count(), DB::table('nl_reward_ledger')->count()];
        $full = $run(); $this->assertSame(560, $full['initial']['hp']); $this->assertSame(44, $full['initial']['agility']); $this->assertSame(84, $full['initial']['atk']); $this->assertSame(74, $full['direct_damage']); $this->assertCount(1, $full['active_sets']);
        $this->assertSame($this->character->id, $full['first_actor']); $this->assertSame(0, min($full['hp_end'])); $this->assertFalse($full['rewards_issued']);
        $service = app(ClientStateService::class); $state = $service->snapshot($this->player, $this->character->id);
        $service->saveEquipment($this->player, $this->character->id, ['revision' => $state['revision'], 'equipment' => ['back' => '']]);
        $partial = $run(); $this->assertSame(460, $partial['initial']['hp']); $this->assertSame(34, $partial['initial']['agility']); $this->assertSame(60, $partial['direct_damage']); $this->assertCount(0, $partial['active_sets']);
        $again = $run(); $this->assertSame($partial, $again);
        $this->assertSame($before, [DB::table('users')->sum('tokens'), DB::table('characters')->sum('gold'), DB::table('nl_claims')->count(), DB::table('nl_reward_ledger')->count()]);
    }
}
