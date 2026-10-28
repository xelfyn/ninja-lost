#!/usr/bin/env python3
"""Fetch pinned text for isolated tests. Never execute an intake hook or follow redirects."""
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

def source_request(url):
    parts = urllib.parse.urlsplit(url)
    if parts.scheme != 'https' or parts.netloc not in ('api.github.com', 'raw.githubusercontent.com') or parts.fragment:
        raise ValueError('Only exact HTTPS GitHub source origins are allowed')
    headers = {'User-Agent': 'NinjaLost-pinned-readonly-audit', 'Accept': 'application/vnd.github+json'}
    # Job-scoped read-only token is sent ONLY to GitHub REST, never raw-file hosts.
    # NoRedirect prevents forwarding credentials to another origin. No headers are logged.
    if parts.netloc == 'api.github.com':
        token = os.environ.get('NL_SOURCE_TOKEN', '')
        if token:
            if '\r' in token or '\n' in token:
                raise ValueError('Invalid source authentication configuration')
            headers['Authorization'] = 'Bearer ' + token
    return urllib.request.Request(url, headers=headers)

def fetch(url, maximum):
    request = source_request(url)
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
    tree = json.loads(fetch('https://api.github.com/repos/' + lock['repository'] + '/git/trees/' + lock['commit'] + '?recursive=1', 20_000_000))
    if tree.get('truncated') is not False:
        raise ValueError('Upstream tree incomplete; cannot assert source counts')
    extensions = ['.as', '.mxml', '.fla', '.as3proj', '.apk', '.swf', '.ane']
    counts = {ext: sum(1 for f in tree['tree'] if f['type'] == 'blob' and f['path'].lower().endswith(ext)) for ext in extensions}
    projects = [{k: f[k] for k in ['path', 'sha', 'size']} for f in tree['tree'] if f['type'] == 'blob' and f['path'].lower().endswith(('.fla', '.as3proj', '.mxml'))]
    report = {'commit': lock['commit'], 'tree_complete': True, 'entries': len(tree['tree']), 'extensions': counts, 'source_project_candidates': projects, 'scope': 'Git tree metadata only; no binary downloads or execution'}
    (dest / 'tree-audit.json').write_text(json.dumps(report, indent=2) + '\n')
    print('CLIENT_TREE_AUDIT', json.dumps(report, sort_keys=True))

if __name__ == '__main__':
    main()
