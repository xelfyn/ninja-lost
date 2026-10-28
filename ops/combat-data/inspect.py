#!/usr/bin/env python3
"""Bounded, read-only inspection of pinned zlib/JSON metadata. No assets, DB or activation."""
import collections
import hashlib
import json
import os
from pathlib import Path
import sys
import urllib.parse
import urllib.request
import zlib

LIMIT = 8 * 1024 * 1024

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        raise ValueError('Redirect refused')

def no_duplicates(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Duplicate JSON key')
        result[key] = value
    return result

def decode(data, limit=LIMIT):
    if not isinstance(data, bytes) or not data or len(data) > limit:
        raise ValueError('Invalid input size')
    if data.lstrip().startswith((b'[', b'{')):
        raw = data
    else:
        dec = zlib.decompressobj()
        raw = dec.decompress(data, limit + 1)
        if len(raw) > limit or dec.unconsumed_tail or not dec.eof or dec.unused_data:
            raise ValueError('Oversized, truncated or trailing compressed input')
    if len(raw) > limit:
        raise ValueError('Expanded size limit exceeded')
    def reject_constant(value):
        raise ValueError('Non-finite JSON constant')
    records = json.loads(raw.decode('utf-8', errors='strict'), object_pairs_hook=no_duplicates, parse_constant=reject_constant)
    if not isinstance(records, list) or len(records) > 20000 or any(not isinstance(x, dict) for x in records):
        raise ValueError('Expected bounded record array')
    return records, len(raw)

def histogram(records, key):
    return dict(sorted(collections.Counter(type(x.get(key)).__name__ for x in records).items()))

def summary(skills, effects):
    ids = [s.get('id') for s in skills]
    effect_ids = [e.get('skill_id') for e in effects]
    nested = []
    invalid_lists = 0
    for record in effects:
        entries = record.get('skill_effect')
        if not isinstance(entries, list) or any(not isinstance(x, dict) for x in entries):
            invalid_lists += 1
        else:
            nested.extend(entries)
    fields = ['damage', 'cp_cost', 'cooldown', 'type', 'level', 'premium']
    effect_fields = ['effect', 'target', 'type', 'duration', 'chance', 'passive', 'no_disperse', 'calc_type', 'reduce_type', 'amount', 'amount_hp', 'amount_cp']
    return {
        'skill_records': len(skills), 'unique_skill_ids': len(set(str(x) for x in ids)),
        'duplicate_skill_ids': len(ids) - len(set(str(x) for x in ids)),
        'effect_records': len(effects), 'duplicate_effect_ids': len(effect_ids) - len(set(str(x) for x in effect_ids)),
        'effect_instances': len(nested), 'malformed_effect_lists': invalid_lists,
        'orphan_effect_records': sum(str(x) not in set(str(i) for i in ids) for x in effect_ids),
        'skills_without_effect_record': len(set(str(x) for x in ids) - set(str(x) for x in effect_ids)),
        'skill_field_types': {key: histogram(skills, key) for key in fields},
        'effect_field_types': {key: histogram(nested, key) for key in effect_fields},
        'zero_cp_records': sum(x.get('cp_cost') in [0, '0'] and not isinstance(x.get('cp_cost'), bool) for x in skills),
        'zero_cooldown_records': sum(x.get('cooldown') in [0, '0'] and not isinstance(x.get('cooldown'), bool) for x in skills),
        'missing_chance_instances': sum(x.get('chance') is None for x in nested),
        'effect_names': dict(sorted(collections.Counter(str(x.get('effect')) for x in nested).items())),
        'target_values': dict(sorted(collections.Counter(str(x.get('target')) for x in nested).items())),
        'effect_type_values': dict(sorted(collections.Counter(str(x.get('type')) for x in nested).items())),
        'calculation_values': dict(sorted(collections.Counter(str(x.get('calc_type')) for x in nested).items())),
        'scope': 'Pinned snapshot metadata counts only. Not unique assets, rights clearance, engine parity or a production import.',
    }

def main():
    if os.environ.get('GITHUB_ACTIONS') != 'true':
        raise ValueError('Network intake is restricted to the isolated CI runner')
    root = Path(__file__).resolve().parents[2]
    dest = Path(sys.argv[1]).resolve()
    temp = Path(os.environ['RUNNER_TEMP']).resolve()
    if dest == temp or temp not in dest.parents or dest.exists() or root == dest or root in dest.parents:
        raise ValueError('Use a fresh owned runner temporary subdirectory')
    lock = json.loads((Path(__file__).parent / 'source-lock.json').read_text())
    dest.mkdir(mode=0o700)
    records = {}
    manifests = []
    for entry in lock['files']:
        url = 'https://raw.githubusercontent.com/' + lock['repository'] + '/' + lock['commit'] + '/' + urllib.parse.quote(entry['path'], safe='/')
        request = urllib.request.Request(url, headers={'User-Agent': 'NinjaLost-G8F-read-only'})
        with urllib.request.build_opener(NoRedirect).open(request, timeout=30) as response:
            data = response.read(entry['bytes'] + 1)
        actual = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
        if len(data) != entry['bytes'] or actual != entry['git_blob']:
            raise ValueError('Pinned metadata mismatch')
        parsed, expanded = decode(data)
        output = entry['local'].replace('.bin', '.json')
        (dest / output).write_text(json.dumps(parsed, ensure_ascii=False, allow_nan=False), encoding='utf-8')
        records[entry['local']] = parsed
        manifests.append({'file': entry['path'], 'git_blob': actual, 'bytes': len(data), 'expanded_bytes': expanded, 'sha256': hashlib.sha256(data).hexdigest()})
    report = summary(records['skills.bin'], records['skill-effect.bin'])
    report.update({'upstream_commit': lock['commit'], 'files': manifests, 'database_records_imported': 0, 'assets_downloaded': 0})
    (dest / 'audit.json').write_text(json.dumps(report, indent=2, sort_keys=True) + '\n')
    print('COMBAT_DATA_AUDIT ' + json.dumps(report, sort_keys=True))

if __name__ == '__main__':
    main()
