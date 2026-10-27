#!/usr/bin/env bash
set -euo pipefail
[[ "${CI:-}" == true && "${DB_DATABASE:-}" == ninja_lost_eval ]] || { echo 'Isolated CI database required'; exit 1; }
work=$(mktemp -d "${RUNNER_TEMP:-/tmp}/nl-gateway-probe.XXXXXX")
chmod 700 "$work"
export NL_GATEWAY_FIXTURE="$work/state.json" NL_GATEWAY_SERVER_LOG="$work/http.log"
# APP_ENV=local is deliberate: Laravel MUST enforce CSRF on real HTTP, not unit-test bypass.
export APP_ENV=local NL_EVALUATION_GATEWAY=true SESSION_DRIVER=database CACHE_STORE=file SESSION_SECURE_COOKIE=false SESSION_COOKIE=nl_gateway_eval
cleanup() {
  code=$?
  if [[ -f "$NL_GATEWAY_FIXTURE" ]]; then
    php ops/gateway/http-fixture.php cleanup > "$work/cleanup.log" 2>&1 || { echo 'Gateway fixture cleanup failed'; code=1; }
  fi
  rm -rf "$work"
  exit "$code"
}
trap cleanup EXIT
php ops/gateway/http-fixture.php prepare
node --test clients/evaluation/client.test.mjs
node ops/gateway/http-probe.mjs
