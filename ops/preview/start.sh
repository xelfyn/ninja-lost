#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
[[ -f vendor/autoload.php ]] || { echo 'Run bash ops/preview/setup.sh first'; exit 1; }
python3 ops/preview/environment.py
php artisan config:clear
printf 'Development preview only. In Codespaces, verify port 8000 visibility is PRIVATE. Stop the codespace when finished.\n'
exec php -S 0.0.0.0:8000 -t public vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
