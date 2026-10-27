"""Repository hygiene, not a complete security audit."""
import json, re, subprocess, sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
BANNED_EXT={'.exe','.dll','.apk','.swf','.p12','.jks','.keystore','.pem','.sql','.zip'}
SECRET=re.compile(rb'-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{30,}|AKIA[0-9A-Z]{16}')
def inspect(path,data):
    p=Path(path); failures=[]
    if p.suffix.lower() in BANNED_EXT or p.name=='.env':failures.append('sensitive/binary path')
    if any(part in {'vendor','node_modules','.git'} for part in p.parts):failures.append('dependency/internal path')
    if len(data)>2*1024*1024 or b'\0' in data:failures.append('binary/large content')
    if SECRET.search(data):failures.append('potential credential')
    try:data.decode('utf-8')
    except UnicodeDecodeError:failures.append('not UTF-8')
    return failures

def main():
    paths=subprocess.check_output(['git','ls-files','-z'],cwd=ROOT).decode().split('\0')
    errors=[];total=0;count=0
    for path in filter(None,paths):
        target=ROOT/path
        if target.is_symlink() or not target.is_file():errors.append(f'Non-regular: {path}');continue
        data=target.read_bytes();count+=1;total+=len(data)
        errors.extend(f'{path}: {e}' for e in inspect(path,data))
    if total>30*1024*1024:errors.append('Review source size above 30 MiB')
    manifest=ROOT/'project/server-intake.json'
    if (ROOT/'composer.json').exists() and not manifest.exists():errors.append('Missing source provenance')
    print('\n'.join(errors) if errors else f'PASS repository hygiene: {count} tracked text files, {total} bytes')
    print('Scope: hygiene only; no claim of gameplay/security completeness.')
    return bool(errors)
if __name__=='__main__':sys.exit(main())
