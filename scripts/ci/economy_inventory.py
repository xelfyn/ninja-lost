"""Conservative writer-candidate inventory; not a complete PHP data-flow proof."""
from pathlib import Path
import hashlib
import re

ROOT = Path(__file__).resolve().parents[2]
REFERENCES = re.compile(r"\b(?:tokens|gold|character_items|character_skills|character_pets|CharacterItem|CharacterSkill|CharacterPet)\b")
MUTATIONS = re.compile(r"(?:->|::)(?:save|update|insert|create|firstOrCreate|updateOrCreate|increment|decrement|delete|destroy|attach|detach|sync|truncate|upsert)\s*\(|(?:tokens|gold|quantity)\s*(?:\+=|-=|=)")
FUNCTION = re.compile(r"\bfunction\s+(\w+)\s*\(")

def candidates(root=ROOT):
    result = []
    for path in sorted((root / 'app').rglob('*.php')):
        source = path.read_text(encoding='utf-8')
        if not (REFERENCES.search(source) and MUTATIONS.search(source)):
            continue
        function = '<class>'
        locations = []
        for number, line in enumerate(source.splitlines(), 1):
            match = FUNCTION.search(line)
            if match:
                function = match.group(1)
            if REFERENCES.search(line):
                locations.append(f'{function}:{number}')
        result.append((str(path.relative_to(root)), hashlib.sha256(source.encode()).hexdigest(), sorted(set(locations))))
    return result

if __name__ == '__main__':
    rows = candidates()
    print('Candidate scan only: co-occurring economy references and writes may include reads/configuration; dynamic paths need review.')
    for path, digest, locations in rows:
        print(f'{path} | sha256={digest} | refs=' + ','.join(locations))
    print(f'CANDIDATE_FILES={len(rows)}')
