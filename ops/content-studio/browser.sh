#!/usr/bin/env bash
set -euo pipefail
[[ "${CI:-}" == true && "${DB_HOST:-}" == 127.0.0.1 && "${DB_DATABASE:-}" == ninja_lost_eval ]] || { echo 'Isolated CI database required'; exit 1; }
qa_root="$(mktemp -d "${RUNNER_TEMP:-/tmp}/nl-studio-browser.XXXXXX")"
trap 'rm -rf "$qa_root"' EXIT
export NODE_PATH="$qa_root/node_modules"
export PLAYWRIGHT_BROWSERS_PATH="$qa_root/browsers"
export NL_QA_STATE="$qa_root/state.json"
export NL_BROWSER_PROOF_DIR="$qa_root/proof"
timeout --kill-after=10s 90s npm install --prefix "$qa_root" --ignore-scripts --no-audit --no-fund --package-lock=false --fetch-retries=2 --fetch-timeout=20000 playwright@1.56.1 > "$qa_root/install.log" 2>&1 || { tail -n 40 "$qa_root/install.log"; exit 1; }
node "$qa_root/node_modules/playwright/cli.js" --version
timeout --kill-after=10s 180s node "$qa_root/node_modules/playwright/cli.js" install --only-shell chromium > "$qa_root/browser-install.log" 2>&1 || { tail -n 60 "$qa_root/browser-install.log"; exit 1; }
timeout --kill-after=10s 35s node ops/browser/preflight.cjs
timeout --kill-after=10s 240s node ops/content-studio/browser.cjs
export NL_QA_STATE="$qa_root/equipment-state.json"
timeout --kill-after=10s 240s node ops/equipment-studio/browser.cjs
