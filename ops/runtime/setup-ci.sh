#!/usr/bin/env bash
set -euo pipefail
[[ "${CI:-}" == "true" && "${DB_DATABASE:-}" == "ninja_lost_eval" && "${DB_HOST:-}" == "127.0.0.1" ]] || { echo 'CI-only isolated MySQL setup'; exit 1; }
mkdir -p bootstrap/cache storage/framework/{cache/data,sessions,views} storage/logs
# Never run community setup hooks, seeders, npm scripts or default-user creation.
composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins
php artisan package:discover --ansi
php artisan migrate --force --no-interaction
php artisan filament:assets
vendor/bin/phpunit --configuration ops/runtime/phpunit.xml --testdox
php artisan route:list --path=admin --except-vendor >/dev/null
