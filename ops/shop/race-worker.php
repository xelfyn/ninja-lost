<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Fixture.php';
use NinjaLost\QA\Fixture;
use App\Models\User;
use App\Services\Shop\ShopPurchase;
use App\Services\Rewards\StaffGrantService;
use App\Services\Rewards\ClientStateService;
Fixture::guard();
[$script, $json, $barrier, $index] = $argv;
$job = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
file_put_contents($barrier.'/ready-'.$index, 'ready');
$deadline = microtime(true) + 20;
while (!is_file($barrier.'/start')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Start barrier timed out'); }
    usleep(10000);
}
try {
    $actor = User::findOrFail($job['actor']);
    $result = match ($job['action']) {
        'shop' => app(ShopPurchase::class)->buy($actor, $job['payload']),
        'staff' => app(StaffGrantService::class)->grant($actor, $job['character'], $job['rewards'], 'QA concurrent shop operation', $job['key']),
        'equipment' => app(ClientStateService::class)->saveEquipment($actor, $job['character'], ['revision' => 0, 'equipment' => ['weapon' => '']]),
        default => throw new RuntimeException('Unsupported QA action'),
    };
    echo json_encode(['ok' => true, 'replayed' => $result['replayed'] ?? false, 'id' => $result['id'] ?? null], JSON_THROW_ON_ERROR);
} catch (Illuminate\Validation\ValidationException $e) {
    echo json_encode(['ok' => false, 'error' => 'validation'], JSON_THROW_ON_ERROR);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
    if ($job['action'] !== 'equipment' || $e->getStatusCode() !== 409) { throw $e; }
    echo json_encode(['ok' => false, 'error' => 'conflict'], JSON_THROW_ON_ERROR);
}
