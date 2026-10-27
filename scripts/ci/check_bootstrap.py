"""Dependency-free, deliberately narrow B00 checks; not a general security scanner."""
from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path
from typing import Mapping

ALLOWED = {
    'README.md', '.gitignore', '.github/workflows/bootstrap.yml',
    '.github/pull_request_template.md', 'docs/STORAGE_POLICY.md',
    'project/source-lock.json', 'scripts/ci/check_bootstrap.py',
    'tests/test_bootstrap.py',
}
MAX_FILE = 64 * 1024
MAX_TOTAL = 256 * 1024


def validate_files(files: Mapping[str, bytes]) -> list[str]:
    errors: list[str] = []
    for path in sorted(ALLOWED - files.keys()):
        errors.append(f'Missing bootstrap file: {path}')
    for path, content in files.items():
        if path not in ALLOWED:
            errors.append(f'Outside B00 allowlist: {path}')
        if len(content) > MAX_FILE:
            errors.append(f'File exceeds B00 size limit: {path}')
        try:
            text = content.decode('utf-8')
        except UnicodeDecodeError:
            errors.append(f'Non-UTF-8 content: {path}')
            continue
        if '\x00' in text:
            errors.append(f'Binary content: {path}')
        if re.search(r'-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----', text):
            errors.append(f'Private key marker: {path}')
    if sum(map(len, files.values())) > MAX_TOTAL:
        errors.append('Bootstrap exceeds total size limit')
    try:
        lock = json.loads(files.get('project/source-lock.json', b'{}'))
        if lock.get('repository') != 'xelfyn/ninja-lost':
            errors.append('Unexpected target repository')
        if lock.get('source_intake_status') != 'not_imported':
            errors.append('B00 must not claim upstream import')
        sha = lock.get('candidate', {}).get('commit', '')
        if not re.fullmatch(r'[a-f0-9]{40}', sha):
            errors.append('Candidate must have an immutable commit pin')
        policy = lock.get('ci_policy', {})
        for key in ('artifact_upload', 'dependency_cache', 'automatic_deployment', 'automatic_codespaces'):
            if policy.get(key) is not False:
                errors.append(f'B00 disallows: {key}')
        if policy.get('runner') != 'ubuntu-24.04' or policy.get('timeout_minutes') != 3:
            errors.append('Unexpected runner or timeout policy')
    except (ValueError, TypeError, AttributeError):
        errors.append('Invalid source-lock structure')
    workflow = files.get('.github/workflows/bootstrap.yml', b'').decode('utf-8', errors='replace')
    # Smoke guard for this exact workflow only; this is not a YAML security parser.
    for required in ('runs-on: ubuntu-24.04', 'timeout-minutes: 3',
                     'contents: read', 'persist-credentials: false', 'cancel-in-progress: true'):
        if required not in workflow:
            errors.append(f'Missing workflow guard: {required}')
    for blocked in ('upload-artifact', 'download-artifact', 'actions/cache', 'schedule:',
                    'pull_request_target:', 'self-hosted', 'contents: write', 'packages: write'):
        if blocked in workflow:
            errors.append(f'Unexpected workflow capability: {blocked}')
    return errors


def main() -> int:
    root = Path(__file__).resolve().parents[2]
    result = subprocess.run(['git', 'ls-files', '-z'], cwd=root, check=True, capture_output=True)
    paths = [p for p in result.stdout.decode('utf-8').split('\0') if p]
    files: dict[str, bytes] = {}
    for relative in paths:
        target = root / relative
        if target.is_symlink() or not target.is_file():
            print(f'FAIL: unexpected non-regular file: {relative}')
            return 1
        files[relative] = target.read_bytes()
    errors = validate_files(files)
    for error in errors:
        print(f'FAIL: {error}')
    if errors:
        return 1
    print(f'PASS: B00 bootstrap checks; {len(files)} files, {sum(map(len, files.values()))} bytes.')
    print('Scope excludes application runtime, game security, Discord, and Android.')
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (OSError, subprocess.CalledProcessError) as exc:
        print(f'FAIL: bootstrap could not be read: {exc}', file=sys.stderr)
        sys.exit(1)
