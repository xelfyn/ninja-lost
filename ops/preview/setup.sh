#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
python3 ops/preview/environment.py
mkdir -p bootstrap/cache storage/framework/{cache/data,sessions,views} storage/logs
composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins
composer dump-autoload --optimize --strict-psr --no-scripts --no-plugins --no-interaction
php artisan config:clear
php artisan package:discover --ansi
php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (!app()->environment("local") || config("database.connections.mysql.host") !== "db" || config("database.connections.mysql.database") !== "ninja_lost_eval") {fwrite(STDERR,"Unexpected preview database\n");exit(1);} for($i=0;$i<40;$i++){try{Illuminate\Support\Facades\DB::select("SELECT 1");exit(0);}catch(Throwable $e){sleep(2);}} fwrite(STDERR,"Preview database unavailable; no destructive reset performed\n");exit(1);'
# No migrate:fresh, no upstream seeders, no imported/default administrator.
php artisan migrate --force --no-interaction
php artisan filament:assets
printf '\nPreview dependencies ready. Next: php artisan ninja-lost:create-admin\nThen: bash ops/preview/start.sh\nKeep port 8000 PRIVATE. No game content has been seeded.\n'
