<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Fixture.php';
use App\Services\Content\EquipmentStudio;
use Illuminate\Support\Facades\DB;
use NinjaLost\QA\EquipmentFixture as EF;
use NinjaLost\QA\Fixture;
use Symfony\Component\Process\Process;
function demand(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function runGearRace(array $inputs): array {
    $dir = sys_get_temp_dir().'/nl-gear-race-'.bin2hex(random_bytes(8)); mkdir($dir, 0700); $workers = [];
    try {
        foreach ($inputs as $i => $input) {
            $p = new Process(['php', __DIR__.'/race-worker.php'], dirname(__DIR__, 2)); $p->setTimeout(25);
            $p->setInput(json_encode($input + ['ready' => "$dir/$i", 'start' => "$dir/start"], JSON_THROW_ON_ERROR)); $p->start(); $workers[] = $p;
        }
        $until = microtime(true) + 15;
        while (count(glob($dir.'/*')) !== count($inputs)) {
            foreach ($workers as $p) if (!$p->isRunning()) throw new RuntimeException('Worker stopped: '.$p->getErrorOutput());
            if (microtime(true) > $until) throw new RuntimeException('Readiness timeout'); usleep(10000);
        }
        touch($dir.'/start'); $out = [];
        foreach ($workers as $p) {
            $p->wait(); demand($p->isSuccessful() && $p->getErrorOutput() === '', 'Worker failed: '.$p->getErrorOutput());
            $row = json_decode($p->getOutput(), true, 32, JSON_THROW_ON_ERROR); demand(is_bool($row['ok'] ?? null), 'Missing worker outcome'); $out[] = $row;
        }
        return $out;
    } finally { foreach ($workers as $p) if ($p->isRunning()) $p->stop(1); foreach (glob($dir.'/*') as $file) unlink($file); rmdir($dir); }
}
$users = []; $draftId = 0;
try {
    Fixture::guard(); for ($i = 0; $i < 8; $i++) $users[] = Fixture::actor(99);
    $d = EF::definition(); $service = app(EquipmentStudio::class); $draft = $service->save($users[0], $d); $draftId = $draft['id'];
    $input = array_map(fn ($u) => ['mode' => 'publish', 'actor' => $u->id, 'draft' => $draftId, 'revision' => 1, 'hash' => $draft['hash']], $users);
    $out = runGearRace($input); demand(count(array_filter($out, fn ($r) => $r['ok'])) === 8, 'Publish retry failed');
    demand(DB::table('nl_studio_releases')->where('draft_id', $draftId)->count() === 1, 'Duplicate release'); $first = $out[0]['result']['id'];
    echo "PASS gear-publish: eight workers, one immutable bundle\n";
    $d['pieces'][0]['modifiers'][0]['amount'] = 25; $next = $service->save($users[0], $d, $draftId, 1);
    $second = $service->publish($users[0], $draftId, 2, $next['hash'])['id'];
    $input = []; foreach ($users as $i => $u) $input[] = ['mode' => 'activate', 'actor' => $u->id, 'release' => $i % 2 ? $first : $second, 'head' => null];
    $out = runGearRace($input); demand(count(array_filter($out, fn ($r) => $r['ok'])) === 4, 'Competing release must conflict');
    $head = DB::table('nl_studio_heads')->where('draft_id', $draftId)->value('release_id');
    demand(in_array($head, [$first, $second], true), 'Head missing');
    demand(DB::table('nl_studio_equipment')->where('draft_id', $draftId)->count() === 2, 'Partial members');
    demand(DB::table('nl_catalog')->whereIn('content_key', array_column($d['pieces'], 'id'))->where('source_revision', 'nl-equipment:'.$head)->count() === 2, 'Partial projection');
    demand(DB::table('nl_operations_audit')->where('action', 'equipment.release.activate')->where('subject', 'draft:'.$draftId)->count() === 1, 'Activation duplicated');
    echo "PASS gear-activation: two competing versions, one complete projection\n";
    $input = []; foreach ($users as $i => $u) { $copy = $d; $copy['name'] = 'Edit '.$i; $input[] = ['mode' => 'edit', 'actor' => $u->id, 'draft' => $draftId, 'revision' => 2, 'definition' => $copy]; }
    $out = runGearRace($input); demand(count(array_filter($out, fn ($r) => $r['ok'])) === 1, 'Stale edits overwritten');
    demand((int) DB::table('nl_studio_drafts')->where('id', $draftId)->value('revision') === 3, 'Wrong edit revision');
    echo "PASS gear-edit: one saved revision and seven conflicts\n";
} catch (Throwable $e) { fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n"); exit(1); }
finally { if ($draftId) EF::cleanup($draftId, array_map(fn ($u) => $u->id, $users)); }
