<?php
// Disposable browser fixture only. Never a login bypass or production seeder.
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('CI') !== 'true' || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'ninja_lost_eval') {
    fwrite(STDERR, "Isolated CI database required.\n"); exit(1);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'ninja_lost_eval') {
    throw new RuntimeException('Unexpected database configuration');
}
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    $email = (string) getenv('NL_QA_EMAIL');
    $password = (string) getenv('NL_QA_PASSWORD');
    if (!preg_match('/^browser_[a-f0-9]+@example\.invalid$/D', $email) || strlen($password) < 24) {
        throw new RuntimeException('Synthetic credentials required');
    }
    \App\Models\User::create([
        'name' => 'Browser QA synthetic admin', 'username' => strstr($email, '@', true),
        'email' => $email, 'password' => $password, 'account_type' => 99, 'tokens' => 0,
    ]);
    $settings = \App\Models\GameConfig::get('game_settings', []);
    $settings['special_deals_visible'] = false;
    \App\Models\GameConfig::set('game_settings', $settings);
    echo "Synthetic browser fixture ready.\n";
} elseif ($mode === 'verify') {
    if ((\App\Models\GameConfig::get('game_settings', [])['special_deals_visible'] ?? null) !== true) {
        throw new RuntimeException('Browser change was not persisted in MySQL');
    }
    echo "PASS independent-process MySQL configuration read.\n";
} else {
    throw new RuntimeException('Unknown fixture operation');
}
