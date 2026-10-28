#!/usr/bin/env bash
# Disposable container verification only. This does not enable preview/game routes for users.
set -euo pipefail
cd "$(dirname "$0")/../.."
[[ "${CI:-}" == true && "${APP_ENV:-}" == testing ]] || { echo 'Isolated CI testing runtime required'; exit 1; }
[[ "${NL_COMBAT_SOURCE:-}" == /tmp/ninja-lost-preview-combat ]] || { echo 'Expected isolated source directory'; exit 1; }
[[ -d "$NL_COMBAT_SOURCE" && ! -L "$NL_COMBAT_SOURCE" ]] || { echo 'Verified source intake required'; exit 1; }
node -e 'if (process.versions.node.split(".")[0] !== "24") process.exit(1); console.log("Preview Node runtime:", process.version)'
node --test ops/training/engine.test.cjs
vendor/bin/phpunit -c ops/training/phpunit.xml --testdox
# The populated-schema upgrade suite remains in the root-owned hosted MySQL job.
# This container uses an unprivileged database account; do not grant CREATE DATABASE.
vendor/bin/phpunit -c ops/trials/phpunit.xml --filter '^(TrialTest|BoundaryTest)::' --testdox
printf 'PASS preview combat runtime: pinned engine, PHP subprocess, owned loadout, training and scoped trial settlement in actual preview image\n'
