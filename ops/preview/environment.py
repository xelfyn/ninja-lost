"""Create only a disposable preview environment; preserve keys and reject unsafe reuse."""
from __future__ import annotations
import json
import os
import re
import secrets
from pathlib import Path


def origin(environ):
    if environ.get('CODESPACES') != 'true':
        return 'http://127.0.0.1:8000'
    name = environ.get('CODESPACE_NAME', '')
    domain = environ.get('GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN', 'app.github.dev')
    if not re.fullmatch(r'[a-z0-9]+(?:-[a-z0-9]+)*', name) or domain != 'app.github.dev':
        raise ValueError('Unrecognized Codespaces origin; review domain before exposing preview')
    return f'https://{name}-8000.{domain}'


def exclusive(path, text):
    if path.is_symlink():
        raise ValueError('Refuse symlink')
    with os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'w') as handle:
        handle.write(text)


def prepare(root, environ):
    root = Path(root).resolve()
    state = root / '.devcontainer/.runtime'
    for directory in (root / '.devcontainer', state):
        if directory.is_symlink():
            raise ValueError('Refuse symlinked state directory')
    state.mkdir(parents=True, exist_ok=True)
    state.chmod(0o700)
    credential_file = state / 'credentials.json'
    mysql_file = state / 'mysql.env'
    app_file = root / '.env'
    if any(p.is_symlink() for p in (credential_file, mysql_file, app_file)):
        raise ValueError('Refuse symlinked configuration')
    if app_file.exists() and not app_file.read_text().startswith('# Ninja Lost isolated preview; generated\n'):
        raise ValueError('Existing .env is not managed by preview setup; no overwrite')
    if credential_file.exists():
        credentials = json.loads(credential_file.read_text())
        if set(credentials) != {'root_password', 'password', 'app_key'} or not all(re.fullmatch('[a-f0-9]{64}', v) for v in credentials.values()):
            raise ValueError('Invalid stored preview credentials')
    else:
        if mysql_file.exists() or app_file.exists():
            raise ValueError('Incomplete state: restore original credentials, do not rotate against existing volume')
        credentials = {key: secrets.token_hex(32) for key in ('root_password', 'password', 'app_key')}
        exclusive(credential_file, json.dumps(credentials, indent=2) + '\n')
    mysql_text = ('MYSQL_DATABASE=ninja_lost_eval\nMYSQL_USER=ninja_lost\n'
                  f"MYSQL_PASSWORD={credentials['password']}\nMYSQL_ROOT_PASSWORD={credentials['root_password']}\n")
    if mysql_file.exists():
        if mysql_file.read_text() != mysql_text:
            raise ValueError('MySQL config differs from saved state; no overwrite')
    else:
        exclusive(mysql_file, mysql_text)
    import base64
    app_key = base64.b64encode(bytes.fromhex(credentials['app_key'])).decode()
    app_origin = origin(environ)
    app_text = ('# Ninja Lost isolated preview; generated\nAPP_NAME="Ninja Lost"\nAPP_ENV=local\nAPP_DEBUG=false\n'
                f'APP_KEY=base64:{app_key}\nAPP_URL={app_origin}\n'
                'DB_CONNECTION=mysql\nDB_HOST=db\nDB_PORT=3306\nDB_DATABASE=ninja_lost_eval\nDB_USERNAME=ninja_lost\n'
                f"DB_PASSWORD={credentials['password']}\n"
                'SESSION_DRIVER=file\nSESSION_HTTP_ONLY=true\nSESSION_SAME_SITE=lax\n'
                f"SESSION_SECURE_COOKIE={'true' if app_origin.startswith('https:') else 'false'}\n"
                'CACHE_STORE=file\nQUEUE_CONNECTION=sync\nMAIL_MAILER=log\nLOG_LEVEL=warning\n')
    if app_file.exists():
        old_lines = app_file.read_text().splitlines()
        expected_lines = app_text.splitlines()
        stable = lambda lines: [line for line in lines if not line.startswith(('APP_URL=', 'SESSION_SECURE_COOKIE='))]
        if stable(old_lines) != stable(expected_lines):
            raise ValueError('Managed .env modified; review instead of overwriting')
        temp = root / '.env.preview-next'
        exclusive(temp, app_text)
        os.replace(temp, app_file)
    else:
        exclusive(app_file, app_text)
    return app_origin

if __name__ == '__main__':
    try:
        prepare(Path(__file__).resolve().parents[2], os.environ)
        print('Preview environment prepared; secrets not printed. No administrator created.')
    except (OSError, ValueError, TypeError) as exc:
        raise SystemExit(f'Preview setup refused: {exc}')
