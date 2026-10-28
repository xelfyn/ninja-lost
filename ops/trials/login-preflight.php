<?php

declare(strict_types=1);

use Filament\Auth\Pages\Login;
use Illuminate\Support\Facades\RateLimiter;
use NinjaLost\QA\Fixture;
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../rewards/Fixture.php';
try {
    Fixture::guard();
    if (config('cache.default') !== 'file') throw new RuntimeException('Expected existing shared file cache');
    // Earlier Studio browser scenarios perform five successful Filament logins on
    // the same loopback IP. Successful logins also consume its 5/minute limit.
    // Query the installed trait's real key and honor the remaining cooldown.
    // Never flush the cache, clear the limiter, alter the IP or disable protection.
    request()->server->set('REMOTE_ADDR', '127.0.0.1');
    $method = new ReflectionMethod(Login::class, 'getRateLimitKey');
    $key = $method->invoke(new Login(), 'authenticate', Login::class);
    $started = microtime(true); $deadline = $started + 65;
    $attempts = RateLimiter::attempts($key);
    $blocked = RateLimiter::tooManyAttempts($key, 5);
    $seconds = RateLimiter::availableIn($key);
    echo json_encode(['qa_login_preflight' => ['attempts' => $attempts, 'limited' => $blocked, 'available_in_seconds' => $seconds]], JSON_THROW_ON_ERROR)."\n";
    while (RateLimiter::tooManyAttempts($key, 5)) {
        if (microtime(true) >= $deadline) throw new RuntimeException('Existing login limiter did not expire within its bounded window');
        usleep(250000);
    }
    printf("PASS trial-login-window: real limiter permits an attempt after %.2fs; no counters reset or authentication bypass\n", microtime(true) - $started);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n"); exit(1);
}
