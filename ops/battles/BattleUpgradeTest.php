<?php

use App\Models\User;
use App\Models\Character;
use App\Services\Rewards\StaffGrantService;
use App\Support\Economy\StateRevision;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use NinjaLost\QA\Fixture;
require_once __DIR__.'/Fixture.php';

final class BattleUpgradeTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $app;
    }
    public function test_nonempty_g8b_schema_upgrades_without_rewriting_existing_receipts_or_revisions(): void
    {
        Fixture::guard(); $this->assertSame('true', getenv('GITHUB_ACTIONS')); $this->assertSame('127.0.0.1', config('database.connections.mysql.host'));
        $original = config('database.connections.mysql'); $database = 'nl_battle_upgrade_'.bin2hex(random_bytes(9)); $created = false;
        try {
            DB::statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true;
            config(['database.connections.mysql.database' => $database]); DB::purge('mysql');
            $migration = base_path('database/migrations/2026_10_28_000003_add_battle_settlement.php');
            // Build the actual pre-battle schema, not later child tables referencing a missing parent.
            $paths = array_values(array_filter(glob(base_path('database/migrations/*.php')), fn ($p) => strcmp(basename($p), basename($migration)) < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true]));
            $create = fn ($role) => User::create(['name' => 'QA battle upgrade', 'username' => 'qa_'.bin2hex(random_bytes(8)), 'email' => bin2hex(random_bytes(8)).'@example.invalid', 'password' => bin2hex(random_bytes(24)), 'account_type' => $role, 'tokens' => 0]);
            $admin = $create(99); $player = $create(0); $c = Character::create(['user_id' => $player->id, 'name' => 'QA Upgrade Character', 'gold' => 0]);
            $key = (string) Str::uuid(); $lines = [['type' => 'gold', 'key' => '', 'quantity' => 50]];
            $receipt = app(StaffGrantService::class)->grant($admin, $c->id, $lines, 'QA existing G8B grant', $key);
            $oldReceipt = (array) DB::table('nl_claims')->where('id', $receipt['id'])->first();
            $oldLedger = json_encode(DB::table('nl_reward_ledger')->orderBy('id')->get(), JSON_THROW_ON_ERROR);
            $oldRevision = StateRevision::current($player->id);
            (require $migration)->up();
            $this->assertTrue(Schema::hasTable('nl_battles')); $this->assertSame(0, DB::table('nl_battles')->count());
            $this->assertSame($oldReceipt, (array) DB::table('nl_claims')->where('id', $receipt['id'])->first());
            $this->assertSame($oldLedger, json_encode(DB::table('nl_reward_ledger')->orderBy('id')->get(), JSON_THROW_ON_ERROR));
            $this->assertSame($oldRevision, StateRevision::current($player->id)); $this->assertSame(50, (int) $c->fresh()->gold);
            $replay = app(StaffGrantService::class)->grant($admin, $c->id, $lines, 'QA existing G8B grant', $key);
            $this->assertTrue($replay['replayed']); $this->assertSame($receipt['id'], $replay['id']);
            $this->assertSame(50, (int) $c->fresh()->gold); $this->assertSame(1, DB::table('nl_claims')->count());
        } finally {
            DB::purge('mysql'); config(['database.connections.mysql' => $original]);
            if ($created) { DB::statement('DROP DATABASE `'.$database.'`'); }
        }
    }
}
