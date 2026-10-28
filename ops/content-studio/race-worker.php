<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Fixture.php';
NinjaLost\QA\Fixture::guard();
[$script, $encoded, $barrier, $index] = $argv;
$job = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
file_put_contents($barrier.'/ready-'.$index, 'ready');
$deadline = microtime(true) + 20;
while (!is_file($barrier.'/start')) { if (microtime(true) > $deadline) throw new RuntimeException('Start barrier timeout'); usleep(10000); }
try {
    $actor = App\Models\User::findOrFail($job['actor']); $s = app(App\Services\Content\SkillStudio::class);
    $result = match ($job['operation']) {
        'publish' => $s->publish($actor, $job['draft'], $job['revision'], $job['hash']),
        'activate' => $s->activate($actor, $job['release'], null),
        'save' => $s->save($actor, $job['definition'], $job['draft'], 1),
        default => throw new RuntimeException('Unknown operation'),
    };
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
    if ($e->getStatusCode() !== 409) throw $e;
    echo json_encode(['ok' => false, 'conflict' => true], JSON_THROW_ON_ERROR);
}
