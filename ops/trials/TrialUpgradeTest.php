<?php

use App\Evaluation\TrainingService;
use App\Models\User;
use App\Services\Rewards\StaffGrantService;
use App\Support\Economy\StateRevision;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use NinjaLost\QA\Fixture;
require_once __DIR__.'/Fixture.php';

final class TrialUpgradeTest extends TestCase
{
    public function createApplication() { $a = require dirname(__DIR__, 2).'/bootstrap/app.php'; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $a; }
    public function test_populated_training_upgrade_retains_actions_claims_and_replay(): void
    {
        Fixture::guard(); $this->assertSame('true', getenv('GITHUB_ACTIONS')); $this->assertSame('127.0.0.1', config('database.connections.mysql.host'));
        $original = config('database.connections.mysql'); $db = 'nl_trial_upgrade_'.bin2hex(random_bytes(8)); $created = false;
        try {
            DB::statement('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true;
            config(['database.connections.mysql.database' => $db]); DB::purge('mysql');
            $migration = base_path('database/migrations/2026_10_28_210000_create_mirror_trials.php');
            $paths = array_values(array_filter(glob(base_path('database/migrations/*.php')), fn ($p) => strcmp(basename($p), basename($migration)) < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true]));
            $this->assertFalse(Schema::hasTable('nl_trial_runs'));
            // Explicit records on our randomly named disposable schema; do not relax the shared fixture guard.
            $create = fn ($role) => User::create(['name' => 'QA upgrade', 'username' => 'qa_'.bin2hex(random_bytes(8)), 'email' => bin2hex(random_bytes(8)).'@example.invalid',
                'password' => bin2hex(random_bytes(24)), 'account_type' => $role, 'tokens' => 0]);
            $u = $create(0); $staff = $create(99);
            $c = App\Models\Character::create(['user_id' => $u->id, 'name' => 'QA upgrade', 'level' => 10, 'rank' => 1, 'gold' => 0]);
            $clear = array_fill_keys(['equipment_weapon', 'equipment_back', 'equipment_clothing', 'equipment_accessory', 'equipment_pet',
                'talent_1', 'talent_2', 'talent_3', 'talent_skills', 'senjutsu_skills', 'senjutsu_equipped_skills', 'senjutsu_type',
                'point_wind', 'point_fire', 'point_lightning', 'point_water', 'point_earth'], 0);
            DB::table('characters')->where('id', $c->id)->update($clear + ['equipment_skills' => '']);
            $key = (string) Str::uuid(); $lines = [['type' => 'gold', 'key' => '', 'quantity' => 7]];
            $grant = app(StaffGrantService::class)->grant($staff, $c->id, $lines, 'QA pre-trial grant', $key);
            $s = app(TrainingService::class); $run = $s->start($u, ['character_id' => $c->id, 'revision' => StateRevision::current($u->id), 'request_key' => (string) Str::uuid()])['run'];
            $command = ['revision' => 0, 'request_key' => (string) Str::uuid(), 'action' => 'charge'];
            $before = $s->action($u, $run['id'], $command); $tables = [];
            foreach (['nl_training_runs', 'nl_training_actions', 'characters', 'nl_account_revisions', 'nl_claims', 'nl_reward_ledger'] as $table) $tables[$table] = DB::table($table)->get()->toJson();
            (require $migration)->up();
            foreach ($tables as $table => $json) $this->assertSame($json, DB::table($table)->get()->toJson());
            $this->assertSame(0, DB::table('nl_trial_runs')->count()); $this->assertSame(0, DB::table('nl_trial_rules')->count());
            $again = $s->action($u, $run['id'], $command); $this->assertTrue($again['replayed']); $this->assertSame($before['run'], $again['run']);
            $repeat = app(StaffGrantService::class)->grant($staff, $c->id, $lines, 'QA pre-trial grant', $key);
            $this->assertTrue($repeat['replayed']); $this->assertSame($grant['id'], $repeat['id']); $this->assertSame(7, (int) $c->fresh()->gold);
            $error = null; try { (require $migration)->down(); } catch (RuntimeException $e) { $error = $e; }
            $this->assertInstanceOf(RuntimeException::class, $error);
        } finally {
            DB::purge('mysql'); config(['database.connections.mysql' => $original]); if ($created) DB::statement('DROP DATABASE `'.$db.'`');
        }
    }
}
