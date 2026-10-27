#!/usr/bin/env bash
set -euo pipefail
[[ "${CI:-}" == 'true' && "${DB_HOST:-}" == '127.0.0.1' && "${DB_DATABASE:-}" == 'ninja_lost_eval' ]] || { echo 'Browser QA requires isolated CI database'; exit 1; }
qa_root="$(mktemp -d "${RUNNER_TEMP:-/tmp}/ninja-lost-browser.XXXXXX")"
trap 'rm -rf "$qa_root"' EXIT
export NODE_PATH="$qa_root/node_modules"
export PLAYWRIGHT_BROWSERS_PATH="$qa_root/browsers"
export NL_BROWSER_PROOF_DIR="$qa_root/proof"
# Pinned package/browser. Hosted Ubuntu already provides the runtime libraries;
# a real launch/render preflight below fails closed if the runner image changes.
printf 'STEP admin browser: install pinned Playwright package\n'
timeout --kill-after=10s 90s npm install --prefix "$qa_root" --ignore-scripts --no-audit --no-fund --package-lock=false --fetch-retries=2 --fetch-timeout=20000 playwright@1.56.1
node "$qa_root/node_modules/playwright/cli.js" --version
printf 'STEP admin browser: download matching Chromium headless shell, without apt changes\n'
timeout --kill-after=10s 180s node "$qa_root/node_modules/playwright/cli.js" install --only-shell chromium
printf 'STEP admin browser: verify actual browser runtime\n'
timeout --kill-after=10s 35s node ops/browser/preflight.cjs
printf 'STEP admin browser: exercise login, settings and restart\n'
timeout --kill-after=10s 180s node ops/browser/check.cjs
