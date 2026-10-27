<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Fixture.php';
use NinjaLost\QA\Fixture;
use NinjaLost\QA\BattleFixture;
use App\Models\User;
use App\Services\Rewards\StaffGrantService;
use App\Services\Rewards\ClientStateService;
Fixture::guard();
[, $json, $barrier, $index] = $argv; $job = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
file_put_contents($barrier.'/ready-'.$index, 'ready'); $deadline = microtime(true) + 25;
while (!is_file($barrier.'/start')) { if (microtime(true) > $deadline) { throw new RuntimeException('Barrier expired'); } usleep(10000); }
try {
    $actor = User::findOrFail($job['actor']);
    $result = match ($job['action']) {
        'finish' => BattleFixture::service()->finish($actor, ['battle_id' => $job['battle'], 'proof' => ['qa_evidence' => 'win']]),
        'start' => BattleFixture::start($actor, $job['character'], $job['rule']),
        'staff' => app(StaffGrantService::class)->grant($actor, $job['character'], [['type' => 'gold', 'key' => '', 'quantity' => 10]], 'QA in-flight battle reward', $job['key']),
        'equipment' => app(ClientStateService::class)->saveEquipment($actor, $job['character'], ['revision' => 0, 'equipment' => ['weapon' => '']]),
        default => throw new RuntimeException('Unsupported worker action'),
    };
    echo json_encode(['ok' => true, 'replayed' => $result['replayed'] ?? false, 'claim_id' => $result['claim_id'] ?? null], JSON_THROW_ON_ERROR);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
    if (!in_array($job['action'], ['start', 'equipment'], true) || $e->getStatusCode() !== 409) { throw $e; }
    echo json_encode(['ok' => false, 'conflict' => true], JSON_THROW_ON_ERROR);
}
