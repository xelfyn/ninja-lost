#!/usr/bin/env python3
"""Fetch four pinned text files for isolated combat tests; never execute an intake hook."""
import hashlib
import json
import os
from pathlib import Path
import sys
import urllib.parse
import urllib.request

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ValueError('Source redirect refused')

def fetch(url, maximum):
    request = urllib.request.Request(url, headers={'User-Agent': 'NinjaLost-G8E-readonly-audit', 'Accept': 'application/vnd.github+json'})
    with urllib.request.build_opener(NoRedirect).open(request, timeout=30) as response:
        data = response.read(maximum + 1)
    if len(data) > maximum:
        raise ValueError('Source size limit exceeded')
    return data

def main():
    root = Path(__file__).resolve().parents[2]
    lock = json.loads((root / 'ops/combat/upstream-lock.json').read_text())
    dest = Path(sys.argv[1]).resolve()
    if dest == root or root in dest.parents or dest.exists():
        raise ValueError('Use a fresh temporary directory outside the checkout')
    dest.mkdir(mode=0o700, parents=False)
    for entry in lock['files']:
        url = 'https://raw.githubusercontent.com/' + lock['repository'] + '/' + lock['commit'] + '/' + urllib.parse.quote(entry['path'], safe='/')
        data = fetch(url, entry['max_bytes'])
        actual = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
        if actual != entry['git_blob']:
            raise ValueError('Pinned Git blob mismatch: ' + entry['path'])
        data.decode('utf-8', errors='strict')
        (dest / entry['local']).write_bytes(data)
        print('PIN_OK', entry['local'], 'bytes=' + str(len(data)), 'sha256=' + hashlib.sha256(data).hexdigest())
    # Metadata only. This is an exact-tree observation, not a license or all-branches claim.
    tree = json.loads(fetch('https://api.github.com/repos/' + lock['repository'] + '/git/trees/' + lock['commit'] + '?recursive=1', 20_000_000))
    if tree.get('truncated') is not False:
        raise ValueError('Upstream tree incomplete; cannot assert source counts')
    extensions = ['.as', '.mxml', '.fla', '.as3proj', '.apk', '.swf', '.ane']
    counts = {ext: sum(1 for f in tree['tree'] if f['type'] == 'blob' and f['path'].lower().endswith(ext)) for ext in extensions}
    report = {'commit': lock['commit'], 'tree_complete': True, 'entries': len(tree['tree']), 'extensions': counts, 'scope': 'Git tree metadata only; no binary downloads or execution'}
    (dest / 'tree-audit.json').write_text(json.dumps(report, indent=2) + '\n')
    print('CLIENT_TREE_AUDIT', json.dumps(report, sort_keys=True))

if __name__ == '__main__':
    main()
