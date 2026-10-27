#!/usr/bin/env bash
set -euo pipefail
[[ "${CI:-}" == true && "${DB_HOST:-}" == 127.0.0.1 && "${DB_DATABASE:-}" == ninja_lost_eval ]] || { echo 'Isolated CI database required'; exit 1; }
qa_root="$(mktemp -d "${RUNNER_TEMP:-/tmp}/nl-reward-browser.XXXXXX")"
trap 'rm -rf "$qa_root"' EXIT
npm install --prefix "$qa_root" --ignore-scripts --no-audit --no-fund --package-lock=false playwright@1.56.1 > "$qa_root/install.log" 2>&1 || { tail -n 30 "$qa_root/install.log"; exit 1; }
node "$qa_root/node_modules/playwright/cli.js" install --with-deps chromium > "$qa_root/browser-install.log" 2>&1 || { tail -n 30 "$qa_root/browser-install.log"; exit 1; }
export NODE_PATH="$qa_root/node_modules"
export NL_QA_STATE="$qa_root/state.json"
export NL_BROWSER_PROOF_DIR="$qa_root/proof"
node ops/rewards/browser.cjs
