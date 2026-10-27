#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
config_root="$(mktemp -d)"
trap 'rm -rf "$config_root"' EXIT
python3 - "$config_root" <<'PY'
import importlib.util, pathlib, shutil, sys
root=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('preview','ops/preview/environment.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
module.prepare(root,{})
for name in ('compose.yaml','Dockerfile','.dockerignore'):
    shutil.copyfile(pathlib.Path('.devcontainer')/name,root/'.devcontainer'/name)
PY
# --quiet avoids printing interpolated secrets. This validates config, not a container build.
docker compose --project-name ninja-lost-config-check -f "$config_root/.devcontainer/compose.yaml" config --quiet
bash -n ops/preview/setup.sh ops/preview/start.sh ops/browser/run.sh
printf 'PASS preview Compose parsing and shell syntax; no container built or started.\n'
