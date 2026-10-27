"""Bounded export of user-requested real UI screenshots from synthetic CI fixtures only."""
import hashlib
import json
import os
from pathlib import Path
import struct
import subprocess
import sys

SELECTION = {
    'shop-admin-desktop.png': 'admin-shop-desktop.png',
    'shop-player-desktop.png': 'player-shop-desktop.png',
    'g8-player-mobile.png': 'player-portal-mobile.png',
    'shop-admin-mobile.png': 'admin-shop-mobile.png',
}
LIMIT = 1024 * 1024

def export(source, target):
    if os.environ.get('CI') != 'true' or os.environ.get('GITHUB_REPOSITORY') != 'xelfyn/ninja-lost':
        raise RuntimeError('Only isolated project CI may export screenshots')
    source, target = Path(source).resolve(), Path(target).resolve()
    runner = Path(os.environ['RUNNER_TEMP']).resolve()
    if runner not in source.parents or runner not in target.parents or target.exists():
        raise RuntimeError('Fresh paths beneath RUNNER_TEMP are required')
    files, total, evidence = {}, 0, []
    for name, output in SELECTION.items():
        path = source / name
        if path.is_symlink():
            raise RuntimeError('Symlink image refused')
        data = path.read_bytes()
        if data[:8] != b'\x89PNG\r\n\x1a\n' or len(data) < 24:
            raise RuntimeError('Invalid PNG')
        width, height = struct.unpack('>II', data[16:24])
        if (width, height) not in [(1440, 1000), (390, 844)]:
            raise RuntimeError('Unexpected viewport')
        total += len(data)
        if total > LIMIT - 16384:
            raise RuntimeError('Visual export exceeds one MiB policy')
        files[output] = data
        evidence.append({'file': output, 'viewport': [width, height], 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
    sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
    manifest = {'source_commit': sha, 'run_id': os.environ.get('GITHUB_RUN_ID'), 'kind': 'actual-Chromium-render',
        'data': 'synthetic evaluation fixtures, not real players', 'visual_review': 'pending until downloaded and inspected',
        'retention_days': 1, 'max_package_bytes': LIMIT, 'screenshots': evidence}
    payload = (json.dumps(manifest, indent=2) + '\n').encode()
    if total + len(payload) > LIMIT:
        raise RuntimeError('Manifest exceeds export policy')
    target.mkdir(mode=0o700)
    for name, data in files.items():
        (target / name).write_bytes(data)
    (target / 'manifest.json').write_bytes(payload)
    print(f'PASS visual-export: {len(files)} real screenshots, {total + len(payload)} bytes, commit {sha}; review pending')

if __name__ == '__main__':
    export(*sys.argv[1:])
