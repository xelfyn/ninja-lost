<?php
/** Synthetic CI-only sentinel; never creates an administrator or uses player data. */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\GameConfig;
use App\Models\User;
function ensure(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
try {
    ensure(getenv('CI') === 'true', 'CI required');
    ensure(app()->environment('local'), 'Local preview required');
    ensure(config('database.default') === 'mysql', 'MySQL required');
    ensure(config('database.connections.mysql.host') === 'db', 'Compose database required');
    ensure(config('database.connections.mysql.database') === 'ninja_lost_eval', 'Evaluation database required');
    ensure(config('session.driver') === 'file', 'Preview file sessions required');
    ensure(User::count() === 0, 'Unexpected account data; no overwrite');
    $file = base_path('.devcontainer/.runtime/ci-probe.json');
    $action = $argv[1] ?? '';
    $session = app('session.store');
    if ($action === 'write') {
        ensure(!file_exists($file), 'Probe already exists');
        $state = [
            'key' => 'ci_preview_' . bin2hex(random_bytes(8)),
            'nonce' => bin2hex(random_bytes(16)),
            'session_id' => bin2hex(random_bytes(20)),
            'key_fingerprint' => hash('sha256', (string) config('app.key')),
        ];
        GameConfig::set($state['key'], ['nonce' => $state['nonce']]);
        $session->setId($state['session_id']); $session->start();
        $session->put('ci_probe_nonce', $state['nonce']); $session->save();
        $handle = fopen($file, 'x');
        ensure($handle !== false, 'Cannot write proof');
        fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR)); fclose($handle); chmod($file, 0600);
        echo "PASS synthetic database and session sentinel written\n";
    } elseif ($action === 'read' || $action === 'cleanup') {
        ensure(is_file($file) && !is_link($file), 'Missing proof');
        $state = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        ensure(str_starts_with($state['key'], 'ci_preview_'), 'Unexpected sentinel key');
        ensure(GameConfig::get($state['key']) === ['nonce' => $state['nonce']], 'Database sentinel lost');
        ensure(hash_equals($state['key_fingerprint'], hash('sha256', (string) config('app.key'))), 'Application key changed');
        $session->setId($state['session_id']); $session->start();
        ensure($session->get('ci_probe_nonce') === $state['nonce'], 'Session sentinel lost');
        if ($action === 'cleanup') {
            GameConfig::where('key', $state['key'])->delete();
            $session->invalidate(); $session->save(); unlink($file);
        }
        echo "PASS database, file-session and application key survived restart\n";
    } else { throw new RuntimeException('Expected write, read or cleanup'); }
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL preview persistence: ' . get_class($e) . "\n");
    exit(1);
}
