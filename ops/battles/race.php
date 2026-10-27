<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Fixture.php';
use NinjaLost\QA\Fixture;
use NinjaLost\QA\BattleFixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Economy\StateRevision;
use Symfony\Component\Process\Process;
Fixture::guard();
function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function workers(array $requests): array
{
    $dir = sys_get_temp_dir().'/nl-battle-race-'.bin2hex(random_bytes(12)); mkdir($dir, 0700); $processes = [];
    try {
        foreach ($requests as $i => $request) {
            $p = new Process([PHP_BINARY, __DIR__.'/race-worker.php', json_encode($request, JSON_THROW_ON_ERROR), $dir, (string) $i]);
            $p->setTimeout(35); $p->start(); $processes[] = $p;
        }
        $deadline = microtime(true) + 30;
        while (count(glob($dir.'/ready-*')) !== count($requests)) {
            check(microtime(true) < $deadline, 'Worker readiness timed out');
            foreach ($processes as $p) { check($p->isRunning(), 'Worker exited before barrier: '.$p->getErrorOutput().$p->getOutput()); }
            usleep(20000);
        }
        touch($dir.'/start'); $results = [];
        foreach ($processes as $p) {
            $p->wait(); check($p->isSuccessful(), 'Worker failed: '.$p->getErrorOutput().$p->getOutput());
            $results[] = json_decode($p->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($processes as $p) { if ($p->isRunning()) { $p->stop(0); } }
        foreach (glob($dir.'/*') as $file) { unlink($file); } rmdir($dir);
    }
}
$users = []; $rules = [];
try {
    $admin = Fixture::actor(99); $users[] = $admin->id; $rule = BattleFixture::rule($admin, 0, 20); $rules[] = $rule['id'];
    $player = function () use (&$users) { $u = Fixture::actor(); $users[] = $u->id; return [$u, Fixture::character($u)]; };
    [$u, $c] = $player(); $t = BattleFixture::start($u, $c->id, $rule['id']); BattleFixture::ready($t['battle_id']);
    $r = workers(array_fill(0, 8, ['action' => 'finish', 'actor' => $u->id, 'battle' => $t['battle_id']]));
    check(count(array_filter($r, fn ($x) => !$x['replayed'])) === 1, 'Battle was issued more than once');
    check(count(array_unique(array_column($r, 'claim_id'))) === 1, 'Receipts differ');
    check((int) $c->fresh()->gold === 20 && StateRevision::current($u->id) === 1, 'Duplicate battle balance/revision');
    echo "PASS battle-eight-finishes: one settlement, one reward and seven original receipts\n";

    [$u, $c] = $player();
    $r = workers(array_fill(0, 8, ['action' => 'start', 'actor' => $u->id, 'character' => $c->id, 'rule' => $rule['id']]));
    check(count(array_filter($r, fn ($x) => $x['ok'])) === 1, 'Multiple active sessions');
    check(DB::table('nl_battles')->where('character_id', $c->id)->count() === 1 && (int) $c->fresh()->gold === 0, 'Start wrote reward');
    echo "PASS battle-eight-starts: one active battle, seven conflicts, no premature reward\n";

    [$u, $c] = $player(); $t = BattleFixture::start($u, $c->id, $rule['id']); BattleFixture::ready($t['battle_id']);
    $jobs = [['action' => 'finish', 'actor' => $u->id, 'battle' => $t['battle_id']]];
    for ($i = 0; $i < 7; $i++) { $jobs[] = ['action' => 'staff', 'actor' => $admin->id, 'character' => $c->id, 'key' => (string) Str::uuid()]; }
    $r = workers($jobs); check(count(array_filter($r, fn ($x) => $x['ok'])) === 8, 'Cross-adapter failure');
    check((int) $c->fresh()->gold === 90 && StateRevision::current($u->id) === 8, 'Lost battle or staff reward');
    $balance = 0;
    $entries = DB::table('nl_reward_ledger as l')->join('nl_claims as c', 'c.id', '=', 'l.claim_id')->where('c.user_id', $u->id)->where('l.kind', 'gold')->orderBy('l.id')->get(['l.*']);
    foreach ($entries as $entry) {
        check((int) $entry->balance_before === $balance, 'Ledger gap'); $balance += (int) $entry->quantity;
        check((int) $entry->balance_after === $balance && $entry->direction === 'credit', 'Ledger mismatch');
    }
    check($balance === 90 && $entries->count() === 8, 'Ledger did not reconcile');
    echo "PASS battle-staff-race: all eight writes retained, gold90, revision8, continuous ledger\n";

    [$u, $c] = $player(); $t = BattleFixture::start($u, $c->id, $rule['id']); BattleFixture::ready($t['battle_id']);
    $jobs = [['action' => 'finish', 'actor' => $u->id, 'battle' => $t['battle_id']]];
    for ($i = 0; $i < 7; $i++) { $jobs[] = ['action' => 'equipment', 'actor' => $u->id, 'character' => $c->id]; }
    $r = workers($jobs); check($r[0]['ok'], 'Battle did not settle');
    $saves = count(array_filter(array_slice($r, 1), fn ($x) => $x['ok']));
    check($saves <= 1 && (int) $c->fresh()->gold === 20 && StateRevision::current($u->id) === 1 + $saves, 'Stale save corrupted settlement');
    echo "PASS battle-old-equipment: settlement preserved; stale saves conflict or one precedes reward\n";
} finally { BattleFixture::cleanup($users, $rules); }
