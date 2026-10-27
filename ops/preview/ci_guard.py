"""Admission checks for a disposable GitHub-hosted preview verification only."""
from __future__ import annotations
import os
import re
from pathlib import Path


def validate_context(root: Path, env: dict[str, str]) -> str:
    if env.get('CI') != 'true' or env.get('GITHUB_ACTIONS') != 'true':
        raise ValueError('GitHub Actions CI required')
    if env.get('GITHUB_REPOSITORY') != 'xelfyn/ninja-lost':
        raise ValueError('Unexpected repository')
    if env.get('RUNNER_ENVIRONMENT') != 'github-hosted':
        raise ValueError('Only a disposable GitHub-hosted runner is allowed')
    root = root.resolve()
    workspace = env.get('GITHUB_WORKSPACE', '')
    if not workspace or Path(workspace).resolve() != root:
        raise ValueError('Workspace mismatch')
    run = env.get('GITHUB_RUN_ID', '')
    attempt = env.get('GITHUB_RUN_ATTEMPT', '')
    if not re.fullmatch(r'[0-9]{1,20}', run) or not re.fullmatch(r'[0-9]{1,5}', attempt):
        raise ValueError('Invalid run identity')
    for relative in ('.env', '.devcontainer/.runtime'):
        candidate = root / relative
        if candidate.exists() or candidate.is_symlink():
            raise ValueError('Existing preview state must not be touched')
    return f'nl-mb01-ci-{run}-{attempt}'


if __name__ == '__main__':
    print(validate_context(Path(__file__).resolve().parents[2], dict(os.environ)))
