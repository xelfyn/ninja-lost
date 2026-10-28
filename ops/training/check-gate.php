<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$routes = array_filter(iterator_to_array(app('router')->getRoutes()), fn ($route) => str_starts_with($route->uri(), 'evaluation/v1/training'));
$expected = ($argv[1] ?? '') === 'enabled' ? 8 : 0;
if (count($routes) !== $expected) { fwrite(STDERR, 'Unexpected training routes'); exit(1); }
echo 'PASS training route gate: '.count($routes)."\n";
