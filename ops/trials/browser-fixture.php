<?php

use Illuminate\Support\Facades\DB;
use NinjaLost\QA\Fixture;
use NinjaLost\QA\TrialFixture as TF;
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Fixture.php';
try {
    Fixture::guard(); $path = getenv('NL_TRIAL_QA_STATE'); if (!$path) throw new RuntimeException('State path required');
    $action = $argv[1] ?? '';
    if ($action === 'prepare') {
        if (file_exists($path)) throw new RuntimeException('Existing QA state must not be overwritten');
        $password = getenv('NL_TRIAL_QA_PASSWORD'); if (!$password || strlen($password) < 32) throw new RuntimeException('Ephemeral credentials required');
        $f = TF::prepare(false, $password);
        if (file_put_contents($path, json_encode($f, JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('State write failed');
        chmod($path, 0600); echo json_encode($f, JSON_THROW_ON_ERROR); exit(0);
    }
    $f = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if ($action === 'ready') {
        $link = DB::table('nl_trial_runs')->where('user_id', $f['player'])->sole(); TF::ready($link->battle_id); echo '{}'; exit(0);
    }
    if ($action === 'verify') {
        $link = DB::table('nl_trial_runs')->where('user_id', $f['player'])->sole(); $battle = DB::table('nl_battles')->where('id', $link->battle_id)->sole();
        $c = DB::table('characters')->where('id', $f['character'])->sole(); $u = DB::table('users')->where('id', $f['player'])->sole();
        $won = $battle->status === 'won'; $claims = DB::table('nl_claims')->where('user_id', $u->id)->pluck('id');
        if ((int) $c->gold !== ($won ? 25 : 0) || (int) $c->xp !== ($won ? 120 : 0) || (int) $c->level !== 10 || (int) $u->tokens !== 0
            || $claims->count() !== ($won ? 2 : 1) || DB::table('nl_reward_ledger')->whereIn('claim_id', $claims)->count() !== ($won ? 5 : 3)
            || DB::table('character_items')->where('character_id', $c->id)->count() !== 2 || DB::table('character_skills')->where('character_id', $c->id)->count() !== 1) throw new RuntimeException('Trial ledger/inventory reconciliation failed');
        echo json_encode(['battle_id' => $battle->id, 'status' => $battle->status, 'claim_id' => $battle->claim_id, 'gold' => (int) $c->gold, 'xp' => (int) $c->xp], JSON_THROW_ON_ERROR); exit(0);
    }
    if ($action === 'cleanup') { TF::cleanup($f); unlink($path); echo '{"cleaned":true}'; exit(0); }
    throw new RuntimeException('Unknown fixture operation');
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
