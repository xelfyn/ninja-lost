#!/usr/bin/env bash
set -euo pipefail
[[ "${CI:-}" == 'true' && "${DB_HOST:-}" == '127.0.0.1' && "${DB_DATABASE:-}" == 'ninja_lost_eval' ]] || { echo 'Browser QA requires isolated CI database'; exit 1; }
qa_root="$(mktemp -d "${RUNNER_TEMP:-/tmp}/ninja-lost-browser.XXXXXX")"
trap 'rm -rf "$qa_root"' EXIT
# Pinned direct dependency; package-manager scripts disabled. No Actions cache/artifact.
npm install --prefix "$qa_root" --ignore-scripts --no-audit --no-fund --package-lock=false playwright@1.56.1
node "$qa_root/node_modules/playwright/cli.js" --version
node "$qa_root/node_modules/playwright/cli.js" install --with-deps chromium
export NODE_PATH="$qa_root/node_modules"
export NL_BROWSER_PROOF_DIR="$qa_root/proof"
node ops/browser/check.cjs
