<?php

use App\Models\User;
use App\Models\Character;
use App\Services\Rewards\StaffGrantService;
use App\Services\Shop\ShopOffers;
use App\Services\Shop\ShopPurchase;
use App\Support\Economy\StateRevision;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use NinjaLost\QA\Fixture;
require_once __DIR__.'/Fixture.php';

final class ShopUpgradeTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $app;
    }
    public function test_populated_g8_history_gets_credit_default_and_remains_replayable(): void
    {
        Fixture::guard(); $this->assertSame('true', getenv('GITHUB_ACTIONS')); $this->assertSame('127.0.0.1', config('database.connections.mysql.host'));
        $original = config('database.connections.mysql'); $database = 'nl_shop_upgrade_'.bin2hex(random_bytes(10)); $created = false;
        try {
            DB::statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true;
            config(['database.connections.mysql.database' => $database]); DB::purge('mysql');
            $migration = base_path('database/migrations/2026_10_28_000002_add_controlled_shop.php');
            $paths = array_values(array_filter(glob(base_path('database/migrations/*.php')), fn ($path) => $path !== $migration));
            $this->assertSame(0, Artisan::call('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true]));
            $create = fn ($role) => User::create(['name' => 'QA shop upgrade', 'username' => 'qa_'.bin2hex(random_bytes(8)), 'email' => bin2hex(random_bytes(8)).'@example.invalid', 'password' => bin2hex(random_bytes(24)), 'account_type' => $role, 'tokens' => 0]);
            $admin = $create(99); $player = $create(0); $character = Character::create(['user_id' => $player->id, 'name' => 'QA upgrade', 'gold' => 0]);
            $key = (string) Str::uuid(); $rewards = [['type' => 'tokens', 'key' => '', 'quantity' => 100], ['type' => 'gold', 'key' => '', 'quantity' => 50]];
            $receipt = app(StaffGrantService::class)->grant($admin, $character->id, $rewards, 'QA pre-shop opening grant', $key);
            $before = DB::table('nl_reward_ledger')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            $claim = (array) DB::table('nl_claims')->where('id', $receipt['id'])->first();
            (require $migration)->up();
            $after = DB::table('nl_reward_ledger')->orderBy('id')->get()->map(function ($r) {
                $this->assertSame('credit', $r->direction); $a = (array) $r; unset($a['direction']); return $a;
            })->all();
            $this->assertSame($before, $after); $this->assertSame($claim, (array) DB::table('nl_claims')->where('id', $receipt['id'])->first());
            $again = app(StaffGrantService::class)->grant($admin, $character->id, $rewards, 'QA pre-shop opening grant', $key);
            $this->assertTrue($again['replayed']); $this->assertSame($receipt['id'], $again['id']);
            $this->assertSame(1, StateRevision::current($player->id));
            $item = 'nlqa_upgrade_'.bin2hex(random_bytes(8));
            DB::table('items')->insert(['item_id' => $item, 'name' => 'QA synthetic upgrade item', 'category' => 'item', 'created_at' => now(), 'updated_at' => now()]);
            $catalog = DB::table('nl_catalog')->insertGetId(['kind' => 'item', 'content_key' => $item, 'title' => 'QA upgrade', 'source_url' => 'https://example.invalid/synthetic', 'source_revision' => 'qa', 'rights_basis' => 'self_authored', 'rights_evidence' => 'Synthetic QA only', 'compatibility_note' => 'No game asset', 'enabled' => true, 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
            $offer = app(ShopOffers::class)->create($admin, ['slug' => 'qa-upgrade-offer', 'title' => 'QA upgrade offer', 'catalog_id' => $catalog, 'price_tokens' => 10, 'price_gold' => 5, 'max_quantity' => 1]);
            app(ShopOffers::class)->setActive($admin, $offer, true);
            app(ShopPurchase::class)->buy($player, ['character_id' => $character->id, 'offer_id' => $offer, 'quantity' => 1, 'request_key' => (string) Str::uuid()]);
            $this->assertSame(90, (int) $player->fresh()->tokens); $this->assertSame(45, (int) $character->fresh()->gold);
            $this->assertSame(2, StateRevision::current($player->id));
            $caught = null;
            try { (require $migration)->down(); } catch (RuntimeException $error) { $caught = $error; }
            $this->assertInstanceOf(RuntimeException::class, $caught); $this->assertSame(2, DB::table('nl_claims')->count());
        } finally {
            DB::purge('mysql'); config(['database.connections.mysql' => $original]);
            if ($created) { DB::statement('DROP DATABASE `'.$database.'`'); }
        }
    }
}
