"""Pinned, text-only intake. Never executes upstream code and never pushes main."""
from __future__ import annotations
import hashlib, json, re, shutil, subprocess, tempfile
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parents[2]
UPSTREAM = 'https://github.com/YoshCasaster/Ninjasage-PrivateServer-community.git'
COMMIT = 'a53f074aaa0690398db947205956ffb97d5671aa'
LICENSE_BLOB = '88218ed71e6e766a10fc75410870ebfc368b5f3c'
PREFIXES = ('app/', 'bootstrap/', 'config/', 'database/migrations/', 'resources/views/', 'routes/')
EXACT = {'artisan', 'composer.json', 'composer.lock', 'public/index.php', 'LICENSE'}
SECRET = re.compile(rb'-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{30,}|AKIA[0-9A-Z]{16}')


def selected(path: str) -> bool:
    p = PurePosixPath(path)
    if p.is_absolute() or '..' in p.parts:
        return False
    return path in EXACT or (path.startswith(PREFIXES) and p.suffix in {'.php', '.md', '.txt'})


def validate_payload(path: str, data: bytes) -> None:
    if len(data) > 2 * 1024 * 1024 or b'\0' in data:
        raise ValueError(f'Unexpected binary/large source: {path}')
    data.decode('utf-8')
    if SECRET.search(data):
        raise ValueError(f'Potential credential in {path}; value redacted')


def git(directory: Path, *args: str) -> str:
    return subprocess.check_output(['git', '-C', str(directory), *args], text=True).strip()


def main() -> None:
    if (ROOT/'composer.json').exists():
        raise SystemExit('Refusing to overwrite an existing application')
    records, groups = [], {}
    with tempfile.TemporaryDirectory() as temporary:
        src = Path(temporary)
        git(src, 'init', '-q')
        git(src, 'remote', 'add', 'origin', UPSTREAM)
        git(src, 'config', 'core.hooksPath', '/dev/null')
        git(src, 'fetch', '--quiet', '--no-tags', '--depth=1', '--filter=blob:none', 'origin', COMMIT)
        if git(src, 'rev-parse', 'FETCH_HEAD') != COMMIT:
            raise SystemExit('Upstream revision mismatch')
        tree = git(src, 'ls-tree', '-rl', COMMIT)
        chosen = []
        for entry in tree.splitlines():
            meta, path = entry.split('\t', 1)
            mode, kind, sha, size = meta.split()
            group = path.split('/')[0]
            totals = groups.setdefault(group, {'files':0,'bytes':0})
            totals['files'] += 1; totals['bytes'] += int(size) if size.isdigit() else 0
            if not selected(path):
                continue
            if mode not in {'100644', '100755'} or kind != 'blob':
                raise SystemExit(f'Reject non-regular source: {path}')
            chosen.append((path, sha))
        # Sparse checkout fetches the selected text trees, never client executables/assets.
        git(src, 'sparse-checkout', 'init', '--no-cone')
        git(src, 'sparse-checkout', 'set', '--no-cone', *['/'+x for x in sorted(EXACT)], *['/'+x for x in PREFIXES])
        git(src, 'checkout', '--quiet', '--detach', COMMIT)
        if git(src, 'hash-object', 'LICENSE') != LICENSE_BLOB:
            raise SystemExit('License changed; manual review required')
        for path, blob in chosen:
            data = (src/path).read_bytes()
            validate_payload(path, data)
            if git(src, 'hash-object', path) != blob:
                raise SystemExit(f'Blob mismatch: {path}')
            dest = 'licenses/UPSTREAM-MIT.txt' if path == 'LICENSE' else path
            out = ROOT/dest
            if out.exists():
                raise SystemExit(f'Unexpected destination collision: {dest}')
            out.parent.mkdir(parents=True, exist_ok=True); out.write_bytes(data)
            records.append({'path':dest,'upstream_path':path,'blob':blob,'sha256':hashlib.sha256(data).hexdigest(),'bytes':len(data)})
    if sum(r['bytes'] for r in records) > 30 * 1024 * 1024:
        raise SystemExit('Review source size before publication')
    # Remove upstream hardcoded-email admin exemptions; do not create any default user.
    user = ROOT/'app/Models/User.php'
    original = user.read_text()
    pattern = r"return \$this->account_type === self::TYPE_ADMIN \|\| in_array\(\$this->email, \[.*?\]\);"
    patched, count = re.subn(pattern, lambda _: r"return \App\Support\NSX\AdminPolicy::allows($this->account_type);", original)
    if count != 1:
        raise SystemExit('Admin policy source changed; review required')
    user.write_text(patched)
    composer = ROOT/'composer.json'
    data = json.loads(composer.read_text())
    data['scripts'] = {'post-autoload-dump':['Illuminate\\Foundation\\ComposerScripts::postAutoloadDump','@php artisan package:discover --ansi']}
    data.setdefault('config', {}).setdefault('audit', {})['block-insecure'] = True
    composer.write_text(json.dumps(data, indent=4)+'\n')
    # Reviewed overlays keep ALL game mutation routes disabled in this evaluation build.
    overlay=ROOT/'ops/overlay'
    for source in sorted(overlay.rglob('*')):
        if source.is_file():
            dest=ROOT/source.relative_to(overlay)
            dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source,dest)
    for r in records:
        r['current_sha256']=hashlib.sha256((ROOT/r['path']).read_bytes()).hexdigest()
        r['modified']=r['current_sha256']!=r['sha256']
    manifest={'upstream':UPSTREAM,'commit':COMMIT,'license_blob':LICENSE_BLOB,'scope':'server text only; no client, assets, certificates, player data or upstream seeders','files':records,'upstream_inventory_summary':groups}
    (ROOT/'project/server-intake.json').write_text(json.dumps(manifest,indent=2)+'\n')
    lock=json.loads((ROOT/'project/source-lock.json').read_text())
    lock.update(batch='MB01',source_intake_status='server_text_imported_runtime_unverified')
    lock['candidate']['component_rights_review']='root MIT notice preserved for server text; separate client/assets NOT imported'
    lock['ci_policy'].update(timeout_minutes=15,artifact_upload=False,dependency_cache=False,automatic_deployment=False,automatic_codespaces=False)
    (ROOT/'project/source-lock.json').write_text(json.dumps(lock,indent=2)+'\n')
    print(json.dumps({'imported_files':len(records),'imported_bytes':sum(r['bytes'] for r in records),'runtime':'NOT_RUN','client_assets':'EXCLUDED'}))

if __name__=='__main__':
    main()
