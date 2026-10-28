<?php

use App\Services\Content\EquipmentStudio;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use NinjaLost\QA\EquipmentFixture as EF;
use NinjaLost\QA\Fixture;
use NinjaLost\QA\LoadoutFixture;
require_once __DIR__.'/Fixture.php';

final class CleanupTest extends TestCase
{
    use DatabaseTransactions;
    public function createApplication() { $app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $app; }
    protected function setUp(): void { parent::setUp(); Fixture::guard(); }
    private function owned(): array
    {
        $staff = Fixture::actor(99); $player = Fixture::actor(); $character = LoadoutFixture::character($player); $definition = EF::definition();
        $version = EF::release($staff, $definition); app(EquipmentStudio::class)->activate($staff, $version['release']['id'], null);
        EF::grantEquip($staff, $player, $character, $definition);
        return ['draft' => $version['draft']['id'], 'release' => $version['release']['id'], 'users' => [$staff->id, $player->id], 'character' => $character->id, 'keys' => array_column($definition['pieces'], 'id')];
    }
    public function test_owned_populated_cleanup_removes_children_before_staff_and_retains_other_fixture(): void
    {
        $own = $this->owned(); $other = $this->owned();
        $before = DB::table('nl_catalog')->whereIn('content_key', $other['keys'])->orderBy('id')->get()->toJson();
        EF::cleanup($own['draft'], $own['users']);
        foreach (['nl_studio_heads', 'nl_studio_releases', 'nl_studio_equipment'] as $table) $this->assertSame(0, DB::table($table)->where('draft_id', $own['draft'])->count());
        $this->assertSame(0, DB::table('nl_studio_drafts')->where('id', $own['draft'])->count());
        $this->assertSame(0, DB::table('users')->whereIn('id', $own['users'])->count());
        $this->assertSame(0, DB::table('items')->whereIn('item_id', $own['keys'])->count());
        $this->assertSame(0, DB::table('nl_catalog')->whereIn('content_key', $own['keys'])->count());
        $this->assertSame(0, DB::table('nl_claims')->whereIn('user_id', $own['users'])->count());
        $this->assertSame($before, DB::table('nl_catalog')->whereIn('content_key', $other['keys'])->orderBy('id')->get()->toJson());
        $this->assertSame(2, DB::table('users')->whereIn('id', $other['users'])->count());
        $this->assertSame(2, DB::table('character_items')->where('character_id', $other['character'])->count());
        $this->assertSame(1, DB::table('nl_claims')->whereIn('user_id', $other['users'])->count());
    }
    public function test_cleanup_failure_is_atomic_and_can_be_retried(): void
    {
        $own = $this->owned(); $fired = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$fired) {
            if (!$fired && str_starts_with($sql, 'delete from `users`')) { $fired = true; throw new RuntimeException('Injected cleanup failure'); }
        });
        try { EF::cleanup($own['draft'], $own['users']); $this->fail('Injected failure expected'); }
        catch (RuntimeException $e) { $this->assertSame('Injected cleanup failure', $e->getMessage()); }
        $this->assertTrue($fired);
        $this->assertSame($own['release'], DB::table('nl_studio_heads')->where('draft_id', $own['draft'])->value('release_id'));
        $this->assertSame(2, DB::table('nl_catalog')->whereIn('content_key', $own['keys'])->count());
        $this->assertSame(2, DB::table('character_items')->where('character_id', $own['character'])->count());
        $this->assertSame(1, DB::table('nl_claims')->whereIn('user_id', $own['users'])->count());
        EF::cleanup($own['draft'], $own['users']);
        $this->assertSame(0, DB::table('users')->whereIn('id', $own['users'])->count());
    }
    public function test_cleanup_requires_own_creator_and_distinct_known_synthetic_actors(): void
    {
        $own = $this->owned(); $foreign = Fixture::actor(99);
        foreach ([[], [$own['users'][1]], [$foreign->id], [$own['users'][0], $own['users'][0]], [$own['users'][0], PHP_INT_MAX]] as $ids) {
            $caught = null; try { EF::cleanup($own['draft'], $ids); } catch (RuntimeException $e) { $caught = $e; }
            $this->assertNotNull($caught); $this->assertSame(1, DB::table('nl_studio_heads')->where('draft_id', $own['draft'])->count());
        }
        $this->assertSame(2, DB::table('character_items')->where('character_id', $own['character'])->count());
    }
}
