<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Fixture.php';
use NinjaLost\QA\Fixture;
use NinjaLost\QA\StudioFixture;
use App\Services\Content\SkillStudio;
use Illuminate\Support\Facades\DB;
Fixture::guard();
function verify(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function runWorkers(array $requests): array
{
    $folder = sys_get_temp_dir().'/nl-studio-race-'.bin2hex(random_bytes(10));
    if (!mkdir($folder, 0700)) throw new RuntimeException('Cannot create barrier');
    $jobs = [];
    try {
        foreach ($requests as $i => $r) {
            $pipes = []; $p = proc_open([PHP_BINARY, __DIR__.'/race-worker.php', json_encode($r, JSON_THROW_ON_ERROR), $folder, (string) $i],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($p)) throw new RuntimeException('Worker start failed');
            fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            $jobs[] = ['p' => $p, 'out' => $pipes[1], 'err' => $pipes[2], 'text' => '', 'errors' => '', 'exit' => null];
        }
        $deadline = microtime(true) + 35; $released = false;
        do {
            $running = false;
            foreach ($jobs as &$j) {
                $j['text'] .= stream_get_contents($j['out']); $j['errors'] .= stream_get_contents($j['err']);
                if ($j['exit'] === null) { $status = proc_get_status($j['p']); if (!$status['running']) $j['exit'] = $status['exitcode']; }
                $running = $running || $j['exit'] === null;
            } unset($j);
            if (!$released && count(glob($folder.'/ready-*')) === count($jobs)) { touch($folder.'/start'); $released = true; }
            verify(microtime(true) < $deadline, 'Race deadline'); if ($running) usleep(20000);
        } while ($running);
        verify($released, 'Readiness not reached'); $results = [];
        foreach ($jobs as $j) {
            $err = $j['errors'].stream_get_contents($j['err']); verify($j['exit'] === 0 && $err === '', 'Worker failed: '.substr($err, 0, 1000));
            $results[] = json_decode($j['text'].stream_get_contents($j['out']), true, 32, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($jobs as $j) { if (proc_get_status($j['p'])['running']) proc_terminate($j['p'], 9); fclose($j['out']); fclose($j['err']); proc_close($j['p']); }
        foreach (glob($folder.'/*') as $file) unlink($file); rmdir($folder);
    }
}
$users = []; $draftIds = [];
try {
    $admins = []; for ($i = 0; $i < 8; $i++) { $admins[] = Fixture::actor(99); $users[] = $admins[$i]->id; }
    $ops = app(SkillStudio::class); $d = StudioFixture::definition(); $draft = $ops->save($admins[0], $d); $draftIds[] = $draft['id'];
    $jobs = array_map(fn ($a) => ['actor' => $a->id, 'operation' => 'publish', 'draft' => $draft['id'], 'revision' => 1, 'hash' => $draft['hash']], $admins);
    $results = runWorkers($jobs);
    verify(count(array_filter($results, fn ($r) => $r['ok'])) === 8, 'Publish retry failed');
    verify(count(array_unique(array_map(fn ($r) => $r['result']['id'], $results))) === 1, 'Duplicate release IDs');
    verify(DB::table('nl_studio_releases')->where('draft_id', $draft['id'])->count() === 1, 'Duplicate release row');
    $first = $results[0]['result']['id'];
    echo "PASS studio-publish: eight ready workers, one immutable release\n";
    $saved = $ops->save($admins[0], array_replace($d, ['damage' => 90]), $draft['id'], 1);
    $second = $ops->publish($admins[0], $draft['id'], 2, $saved['hash'])['id'];
    $jobs = []; foreach ($admins as $i => $a) $jobs[] = ['actor' => $a->id, 'operation' => 'activate', 'release' => $i % 2 === 0 ? $first : $second];
    $results = runWorkers($jobs);
    verify(count(array_filter($results, fn ($r) => $r['ok'])) === 4, 'One version must win; other version must conflict');
    $head = DB::table('nl_studio_heads')->where('draft_id', $draft['id'])->sole();
    verify(in_array($head->release_id, [$first, $second], true), 'Unknown head');
    $projection = DB::table('nl_catalog')->where('content_key', $d['id'])->sole();
    verify($projection->source_revision === 'nl-studio:'.$head->release_id, 'Partial activation');
    verify(DB::table('nl_operations_audit')->where('action', 'studio.release.activate')->whereIn('actor_id', $users)->count() === 1, 'Duplicate activation audit');
    echo "PASS studio-activation: two competing versions, one head and matching backend projection\n";
    $editD = StudioFixture::definition(); $edit = $ops->save($admins[0], $editD); $draftIds[] = $edit['id'];
    $jobs = []; foreach ($admins as $i => $a) $jobs[] = ['actor' => $a->id, 'operation' => 'save', 'draft' => $edit['id'], 'definition' => array_replace($editD, ['name' => 'QA competing edit '.$i])];
    $results = runWorkers($jobs);
    verify(count(array_filter($results, fn ($r) => $r['ok'])) === 1, 'Lost-edit detection failed');
    verify((int) DB::table('nl_studio_drafts')->where('id', $edit['id'])->value('revision') === 2, 'Draft version advanced more than once');
    echo "PASS studio-edit: eight ready workers, one saved revision and seven conflicts\n";
} finally { StudioFixture::cleanup($users, $draftIds); }
