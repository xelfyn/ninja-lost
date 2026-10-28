<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Content\EquipmentStudio;
use App\Services\Rewards\StaffGrantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use NinjaLost\QA\EquipmentFixture as EF;
use NinjaLost\QA\Fixture;
use NinjaLost\QA\LoadoutFixture;
use NinjaLost\QA\StudioFixture;
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../equipment-studio/Fixture.php'; require __DIR__.'/../content-studio/Fixture.php';
try {
    Fixture::guard(); $file = getenv('NL_TRAINING_QA_STATE'); if (!$file) throw new RuntimeException('Owned state path required');
    $action = $argv[1] ?? '';
    if ($action === 'prepare') {
        $data = DB::transaction(function () {
            $password = getenv('NL_TRAINING_QA_PASSWORD'); if (!$password || strlen($password) < 32) throw new RuntimeException('Ephemeral password required');
            $staff = Fixture::actor(99); $player = Fixture::actor(0, null, $password);
            $character = LoadoutFixture::character($player);
            $gear = EF::definition(); $r = EF::release($staff, $gear); app(EquipmentStudio::class)->activate($staff, $r['release']['id'], null);
            $skill = StudioFixture::definition(['name' => 'Training pulse', 'cp_cost' => 12, 'cooldown' => 3,
                'effects' => [['code' => 'stun', 'chance' => 100, 'duration' => 1, 'amount' => 0], ['code' => 'burn', 'chance' => 100, 'duration' => 2, 'amount' => 10]]]);
            $s = StudioFixture::release($staff, $skill, true);
            $lines = array_map(fn ($p) => ['type' => 'item', 'key' => $p['id'], 'quantity' => 1], $gear['pieces']);
            $lines[] = ['type' => 'skill', 'key' => $skill['id'], 'quantity' => 1];
            app(StaffGrantService::class)->grant($staff, $character->id, $lines, 'Isolated training browser fixture', (string) Str::uuid());
            return ['users' => [$staff->id, $player->id], 'player' => $player->id, 'character' => $character->id, 'username' => $player->username,
                'gear_draft' => $r['draft']['id'], 'skill_draft' => $s['draft']['id'], 'weapon' => $gear['pieces'][0]['id'], 'back' => $gear['pieces'][1]['id'], 'skill' => $skill['id']];
        });
        if (file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('State write failed');
        chmod($file, 0600); echo json_encode($data, JSON_THROW_ON_ERROR); exit(0);
    }
    $data = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
    $users = DB::table('users')->whereIn('id', $data['users'])->get();
    if ($users->count() !== 2 || $users->contains(fn ($u) => !str_starts_with($u->username, 'nlqa_'))) throw new RuntimeException('Not owned synthetic users');
    if ($action === 'verify') {
        $run = DB::table('nl_training_runs')->where('user_id', $data['player'])->first();
        if (!$run || DB::table('nl_training_runs')->where('user_id', $data['player'])->count() !== 1) throw new RuntimeException('Expected one persisted training run');
        $actions = DB::table('nl_training_actions')->where('run_id', $run->id)->count();
        $user = DB::table('users')->where('id', $data['player'])->first(); $character = DB::table('characters')->where('id', $data['character'])->first();
        $claims = DB::table('nl_claims')->where('user_id', $data['player'])->pluck('id');
        if ($actions !== (int) $run->revision || (int) $user->tokens !== 0 || (int) $character->gold !== 0 || (int) $character->xp !== 0 || (int) $character->level !== 10
            || $claims->count() !== 1 || DB::table('nl_reward_ledger')->whereIn('claim_id', $claims)->count() !== 3
            || DB::table('character_items')->where('character_id', $data['character'])->count() !== 2
            || DB::table('character_skills')->where('character_id', $data['character'])->count() !== 1) throw new RuntimeException('Training state/economy reconciliation failed');
        echo json_encode(['run_id' => $run->id, 'revision' => (int) $run->revision, 'actions' => $actions, 'status' => $run->status, 'no_reward_writes' => true], JSON_THROW_ON_ERROR); exit(0);
    }
    if ($action === 'cleanup') {
        DB::transaction(function () use ($data) {
            $runs = DB::table('nl_training_runs')->whereIn('user_id', $data['users'])->pluck('id');
            DB::table('nl_training_actions')->whereIn('run_id', $runs)->delete(); DB::table('nl_training_runs')->whereIn('id', $runs)->delete();
            $claims = DB::table('nl_claims')->whereIn('user_id', $data['users'])->pluck('id');
            DB::table('nl_reward_ledger')->whereIn('claim_id', $claims)->delete(); DB::table('nl_claims')->whereIn('id', $claims)->delete();
            DB::table('sessions')->whereIn('user_id', $data['users'])->delete();
            StudioFixture::cleanup([], [$data['skill_draft']]); EF::cleanup($data['gear_draft'], $data['users']);
        });
        unlink($file); echo '{"cleaned":true}'; exit(0);
    }
    throw new RuntimeException('Unknown fixture operation');
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
