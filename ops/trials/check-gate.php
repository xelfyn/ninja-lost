<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$routes = array_filter(iterator_to_array(app('router')->getRoutes()), fn ($r) => str_starts_with($r->uri(), 'evaluation/v1/trials'));
$expected = ($argv[1] ?? '') === 'enabled' ? 7 : 0;
if (count($routes) !== $expected) { fwrite(STDERR, 'Unexpected trial routes'); exit(1); }
echo 'PASS trial routes: '.count($routes)."\n";
