"""Bounded HTTP checks inside the ephemeral app container; no external requests."""
from __future__ import annotations
import os
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = 'http://127.0.0.1:8000'


class LocalRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        parsed = urllib.parse.urlsplit(newurl)
        if f'{parsed.scheme}://{parsed.netloc}' != BASE:
            raise RuntimeError('Refuse non-local redirect')
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def main() -> None:
    if os.environ.get('CI') != 'true':
        raise SystemExit('CI-only HTTP probe')
    client = urllib.request.build_opener(urllib.request.ProxyHandler({}), LocalRedirect())
    for _ in range(30):
        try:
            with client.open(BASE + '/up', timeout=2) as r:
                if r.status == 200:
                    break
        except (OSError, urllib.error.URLError):
            time.sleep(1)
    else:
        raise RuntimeError('Preview did not start')
    for path in ('/admin', '/admin/login'):
        with client.open(BASE + path, timeout=5) as r:
            body = r.read(2_000_000).decode('utf-8')
            if r.status != 200 or urllib.parse.urlsplit(r.url).path != '/admin/login':
                raise RuntimeError('Unexpected guest response')
            if 'Ninja Lost' not in body or 'password' not in body.lower():
                raise RuntimeError('Missing real login form')
    for path in ('/css/filament/filament/app.css', '/js/filament/filament/app.js'):
        with client.open(BASE + path, timeout=5) as r:
            if r.status != 200 or len(r.read(2_000_000)) < 100:
                raise RuntimeError('Missing published panel asset')
    for path in ('/.env', '/amf', '/gateway.php'):
        try:
            client.open(BASE + path, timeout=5)
        except urllib.error.HTTPError as exc:
            if exc.code == 404:
                continue
            raise RuntimeError('Unexpected disabled endpoint response') from None
        raise RuntimeError('Disabled path exposed')
    print('PASS HTTP login, guest redirect, CSS/JS assets and disabled paths')


if __name__ == '__main__':
    main()
