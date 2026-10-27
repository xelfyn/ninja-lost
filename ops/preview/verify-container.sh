#!/usr/bin/env bash
# This script may remove only its newly created CI Compose project's volumes.
set -euo pipefail
cd "$(dirname "$0")/../.."
root="$(pwd -P)"
project="$(python3 ops/preview/ci_guard.py)"
uid="$(id -u)"; gid="$(id -g)"
compose=(docker compose --ansi never --project-name "$project" --file .devcontainer/compose.yaml)
cleanup() {
  status=$?
  trap - EXIT
  "${compose[@]}" down --volumes --remove-orphans --timeout 15 >/dev/null 2>&1 || true
  # The checkout is disposable, never a user's workstation. Restore ownership for checkout cleanup.
  sudo chown -R "$uid:$gid" "$root" || true
  rm -f "$root/.env" "$root/.env.preview-next"
  rm -rf "$root/.devcontainer/.runtime"
  exit "$status"
}
python3 ops/preview/environment.py
trap cleanup EXIT
"${compose[@]}" config --quiet
"${compose[@]}" build --pull app
printf 'PASS container image build\n'
# Match the devcontainer user while retaining isolation to this disposable checkout.
sudo chown -R 1000:1000 "$root"
"${compose[@]}" up -d --wait --wait-timeout 180
"${compose[@]}" exec -T -e CI=true app bash ops/preview/setup.sh
printf 'PASS actual preview setup\n'
"${compose[@]}" exec -T -e CI=true -e APP_ENV=testing app vendor/bin/phpunit --configuration ops/runtime/phpunit.xml --testdox
"${compose[@]}" exec -T -e CI=true app php ops/preview/persistence-probe.php write
"${compose[@]}" exec -T -d app bash ops/preview/start.sh
"${compose[@]}" exec -T -e CI=true app python3 ops/preview/http-probe.py
"${compose[@]}" restart --timeout 15 db app
"${compose[@]}" up -d --wait --wait-timeout 180
# Re-run the normal setup: it must not rotate credentials or destroy the database.
"${compose[@]}" exec -T -e CI=true app bash ops/preview/setup.sh
"${compose[@]}" exec -T -e CI=true app php ops/preview/persistence-probe.php read
"${compose[@]}" exec -T -d app bash ops/preview/start.sh
"${compose[@]}" exec -T -e CI=true app python3 ops/preview/http-probe.py
"${compose[@]}" exec -T -e CI=true app php ops/preview/persistence-probe.php cleanup
printf 'PASS preview restart, session persistence, unchanged key and idempotent setup\n'
printf 'Scope: actual Docker Compose preview; no live Codespace and no visual approval.\n'
