<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Fixture.php';
use App\Models\User;
use App\Services\Content\EquipmentStudio;
use NinjaLost\QA\Fixture;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
try {
    Fixture::guard(); $input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $staff = User::findOrFail($input['actor']); $service = app(EquipmentStudio::class);
    touch($input['ready']); $until = microtime(true) + 15;
    while (!file_exists($input['start'])) { if (microtime(true) > $until) throw new RuntimeException('Barrier timeout'); usleep(10000); }
    try {
        $result = match ($input['mode']) {
            'publish' => $service->publish($staff, $input['draft'], $input['revision'], $input['hash']),
            'activate' => $service->activate($staff, $input['release'], $input['head']),
            'edit' => $service->save($staff, $input['definition'], $input['draft'], $input['revision']),
            default => throw new RuntimeException('Unknown race'),
        };
        echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
    } catch (HttpExceptionInterface $e) {
        if ($e->getStatusCode() !== 409) throw $e;
        echo json_encode(['ok' => false, 'status' => 409], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $e) { fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n"); exit(1); }
