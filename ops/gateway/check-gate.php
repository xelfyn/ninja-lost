<?php
if (getenv('CI') !== 'true') { throw new RuntimeException('CI route inventory only.'); }
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (app('router')->getRoutes() as $route) {
    if (str_starts_with($route->uri(), 'evaluation/v1')) { throw new RuntimeException('Evaluation gateway unexpectedly published.'); }
}
echo 'PASS fresh-process gateway routes absent: '.app()->environment()."\n";
