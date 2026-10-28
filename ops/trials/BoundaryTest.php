<?php

use App\Evaluation\TrainingService;
use App\Evaluation\Trials\TrialService;
use App\Models\User;
use App\Support\Economy\StateRevision;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use NinjaLost\QA\Fixture;
use NinjaLost\QA\TrialFixture as TF;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
require_once __DIR__.'/Fixture.php';

final class BoundaryTest extends TestCase
{
    use DatabaseTransactions;
    public function createApplication() { $a = require dirname(__DIR__, 2).'/bootstrap/app.php'; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $a; }
    private function rejected(callable $fn): void
    {
        $error = null; try { $fn(); } catch (HttpExceptionInterface $e) { $error = $e; }
        $this->assertNotNull($error); $this->assertSame(409, $error->getStatusCode());
    }
    public function test_empty_equipment_map_survives_storage_and_action_replay(): void
    {
        Fixture::guard(); $f = TF::prepare(); $u = User::findOrFail($f['player']); $service = app(TrainingService::class);
        $service->saveLoadout($u, $f['character'], ['revision' => StateRevision::current($u->id), 'equipment' => ['weapon' => '', 'back' => '', 'clothing' => '', 'accessory' => ''], 'skills' => []]);
        $run = $service->start($u, ['character_id' => $f['character'], 'revision' => StateRevision::current($u->id), 'request_key' => (string) Str::uuid()])['run'];
        $row = DB::table('nl_training_runs')->where('id', $run['id'])->first();
        $this->assertInstanceOf(stdClass::class, json_decode($row->bundle)->snapshot->equipment->equipped);
        $command = ['revision' => 0, 'request_key' => (string) Str::uuid(), 'action' => 'charge'];
        $first = $service->action($u, $run['id'], $command); $again = $service->action($u, $run['id'], $command);
        $this->assertSame(1, $first['run']['revision']); $this->assertTrue($again['replayed']); $this->assertSame($first['run'], $again['run']);
        $this->assertSame($row->bundle, DB::table('nl_training_runs')->where('id', $run['id'])->value('bundle'));
        $this->assertSame($row->bundle_hash, DB::table('nl_training_runs')->where('id', $run['id'])->value('bundle_hash'));
    }
    public function test_modified_persisted_command_cannot_reuse_a_terminal_reward_result(): void
    {
        Fixture::guard(); $f = TF::prepare(); $u = User::findOrFail($f['player']); $staff = User::findOrFail($f['staff']); $service = app(TrialService::class);
        $t = $service->start($u, TF::input($f, TF::rule($staff)))['trial']; TF::ready($t['battle_id']);
        $t = TF::win($u, $service->get($u, $t['battle_id']));
        $before = DB::table('nl_reward_ledger')->get()->toJson();
        $row = DB::table('nl_training_actions')->where('run_id', $t['run']['id'])->orderBy('sequence')->first();
        DB::table('nl_training_actions')->where('id', $row->id)->update(['command' => '{"action":"run"}']);
        $this->rejected(fn () => $service->finish($u, $t['battle_id'], []));
        $this->assertSame($before, DB::table('nl_reward_ledger')->get()->toJson());
        $this->assertNull(DB::table('nl_battles')->where('id', $t['battle_id'])->value('result'));
    }
}
