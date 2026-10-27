<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Fixture.php';
use NinjaLost\QA\Fixture;
use NinjaLost\QA\ShopFixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Economy\StateRevision;
Fixture::guard();
function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function race(array $requests): array
{
    $folder = sys_get_temp_dir().'/nl-shop-race-'.bin2hex(random_bytes(12));
    if (!mkdir($folder, 0700)) { throw new RuntimeException('Cannot create barrier'); }
    $jobs = [];
    try {
        foreach ($requests as $index => $request) {
            $pipes = [];
            $proc = proc_open([PHP_BINARY, __DIR__.'/race-worker.php', json_encode($request, JSON_THROW_ON_ERROR), $folder, (string) $index], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) { throw new RuntimeException('Cannot start worker'); }
            fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            $jobs[] = ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2], 'text' => '', 'errors' => '', 'exit' => null];
        }
        $deadline = microtime(true) + 35; $released = false;
        do {
            $pending = false;
            foreach ($jobs as &$job) {
                $job['text'] .= stream_get_contents($job['out']); $job['errors'] .= stream_get_contents($job['err']);
                if ($job['exit'] === null) {
                    $state = proc_get_status($job['proc']);
                    if (!$state['running']) { $job['exit'] = $state['exitcode']; }
                }
                $pending = $pending || $job['exit'] === null;
            } unset($job);
            if (!$released && count(glob($folder.'/ready-*')) === count($jobs)) { touch($folder.'/start'); $released = true; }
            check(microtime(true) <= $deadline, 'Workers or readiness timed out');
            if ($pending) { usleep(20000); }
        } while ($pending);
        check($released, 'Not all workers reached ready barrier');
        $results = [];
        foreach ($jobs as $job) {
            $text = $job['text'].stream_get_contents($job['out']); $error = $job['errors'].stream_get_contents($job['err']);
            check($job['exit'] === 0 && $error === '', 'Worker failed: '.substr($error, 0, 1500));
            $results[] = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($jobs as $job) {
            if (proc_get_status($job['proc'])['running']) { proc_terminate($job['proc'], 9); }
            fclose($job['out']); fclose($job['err']); proc_close($job['proc']);
        }
        foreach (glob($folder.'/*') as $file) { unlink($file); } rmdir($folder);
    }
}
$users = []; $offers = []; $catalogs = [];
try {
    $admin = Fixture::actor(99); $users[] = $admin->id;
    $content = Fixture::content($admin, 'item', 100); $catalogs[] = $content['id'];
    $offer = ShopFixture::offer($admin, $content['id']); $offers[] = $offer;
    $player = function (int $tokens, int $gold) use (&$users) {
        $u = Fixture::actor(); $users[] = $u->id; $c = Fixture::character($u);
        $u->update(['tokens' => $tokens]); $c->update(['gold' => $gold]); return [$u, $c];
    };
    $purchase = fn ($u, $c, $id, $key) => ['action' => 'shop', 'actor' => $u->id, 'payload' => ['character_id' => $c->id, 'offer_id' => $id, 'quantity' => 1, 'request_key' => $key]];
    [$u, $c] = $player(100, 100); $key = (string) Str::uuid();
    $results = race(array_fill(0, 8, $purchase($u, $c, $offer, $key)));
    check(count(array_filter($results, fn ($r) => $r['ok'] && !$r['replayed'])) === 1, 'Duplicate purchase');
    check(count(array_filter($results, fn ($r) => $r['ok'])) === 8, 'Retry did not return receipt');
    check(count(array_unique(array_column($results, 'id'))) === 1 && (int) $u->fresh()->tokens === 90, 'Retry receipt or charge mismatch');
    echo "PASS shop-idempotency: eight ready workers, one charge and seven identical receipts\n";

    [$u, $c] = $player(30, 15); $requests = [];
    for ($i = 0; $i < 8; $i++) { $requests[] = $purchase($u, $c, $offer, (string) Str::uuid()); }
    $results = race($requests);
    check(count(array_filter($results, fn ($r) => $r['ok'])) === 3, 'Overspend count');
    check((int) $u->fresh()->tokens === 0 && (int) $c->fresh()->gold === 0 && StateRevision::current($u->id) === 3, 'Overspend balance/revision');
    check((int) DB::table('character_items')->where('character_id', $c->id)->sum('quantity') === 3, 'Overspend inventory');
    echo "PASS shop-insufficient-funds-race: eight requests, exactly three purchases and nonnegative zero balances\n";

    [$u, $c] = $player(40, 20); $requests = [];
    for ($i = 0; $i < 8; $i++) {
        $requests[] = $i % 2 === 0 ? $purchase($u, $c, $offer, (string) Str::uuid()) : ['action' => 'staff', 'actor' => $admin->id, 'character' => $c->id, 'key' => (string) Str::uuid(), 'rewards' => [['type' => 'tokens', 'key' => '', 'quantity' => 10], ['type' => 'gold', 'key' => '', 'quantity' => 5]]];
    }
    $results = race($requests); check(count(array_filter($results, fn ($r) => $r['ok'])) === 8, 'Mixed grant/purchase failed');
    check((int) $u->fresh()->tokens === 40 && (int) $c->fresh()->gold === 20 && StateRevision::current($u->id) === 8, 'Lost credit/debit update');
    foreach (['tokens' => 40, 'gold' => 20] as $kind => $opening) {
        $balance = $opening;
        $entries = DB::table('nl_reward_ledger as l')->join('nl_claims as c', 'c.id', '=', 'l.claim_id')->where('c.user_id', $u->id)->where('l.kind', $kind)->orderBy('l.id')->get(['l.*']);
        foreach ($entries as $entry) {
            check((int) $entry->balance_before === $balance, 'Ledger chain gap');
            $balance += ($entry->direction === 'debit' ? -1 : 1) * (int) $entry->quantity;
            check((int) $entry->balance_after === $balance, 'Ledger arithmetic mismatch');
        }
        check($balance === $opening && $entries->count() === 8, 'Ledger reconciliation');
    }
    echo "PASS shop-staff-credit-debit-race: eight workers, exact balances, revision eight and continuous signed ledger\n";

    $limited = Fixture::content($admin, 'item', 3); $catalogs[] = $limited['id'];
    $limitedOffer = ShopFixture::offer($admin, $limited['id']); $offers[] = $limitedOffer; $requests = [];
    for ($i = 0; $i < 8; $i++) {
        [$u, $c] = $player(100, 100);
        $requests[] = $i % 2 === 0 ? $purchase($u, $c, $limitedOffer, (string) Str::uuid()) : ['action' => 'staff', 'actor' => $admin->id, 'character' => $c->id, 'key' => (string) Str::uuid(), 'rewards' => [['type' => 'item', 'key' => $limited['key'], 'quantity' => 1]]];
    }
    $results = race($requests); check(count(array_filter($results, fn ($r) => $r['ok'])) === 3, 'Shared supply overissued');
    check((int) DB::table('nl_catalog')->where('id', $limited['id'])->value('issued') === 3, 'Supply counter');
    check((int) DB::table('character_items')->where('item_id', $limited['key'])->sum('quantity') === 3, 'Supply inventory');
    check((int) DB::table('nl_reward_ledger')->where('catalog_id', $limited['id'])->sum('quantity') === 3, 'Supply ledger');
    foreach ($requests as $index => $request) {
        if ($request['action'] === 'shop') {
            check((int) DB::table('users')->where('id', $request['actor'])->value('tokens') === ($results[$index]['ok'] ? 90 : 100), 'Rejected purchase retained debit');
        }
    }
    echo "PASS shop-staff-shared-stock: eight accounts, exactly three units; rejected purchases retain original funds\n";

    [$u, $c] = $player(100, 100); $requests = [['action' => 'equipment', 'actor' => $u->id, 'character' => $c->id]];
    for ($i = 0; $i < 7; $i++) { $requests[] = $purchase($u, $c, $offer, (string) Str::uuid()); }
    $results = race($requests);
    check(count(array_filter(array_slice($results, 1), fn ($r) => $r['ok'])) === 7, 'Equipment race purchase failed');
    check((int) $u->fresh()->tokens === 30 && (int) $c->fresh()->gold === 65, 'Old save overwrote purchase');
    check(StateRevision::current($u->id) === ($results[0]['ok'] ? 8 : 7), 'Equipment revision ordering');
    echo "PASS shop-equipment-race: seven purchases retained; old save serialized first or rejected with 409\n";
} finally { ShopFixture::cleanup($users, $offers, $catalogs); }
