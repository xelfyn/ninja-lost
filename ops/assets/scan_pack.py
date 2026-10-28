#!/usr/bin/env python3
"""Read-only inventory scanner for operator-provided Ninja Saga-compatible asset packs.

The scanner does not execute SWF, infer game mechanics, copy binaries, or publish assets.
It produces metadata that can later be reviewed and passed to a separate extraction/render step.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import sys

MAX_FILES = 20000
MAX_FILE_BYTES = 32 * 1024 * 1024

PATTERNS = (
    ("skill-icon-pack", re.compile(r"^skill_icon_(\d+)-(\d+)\.swf$", re.I), None),
    ("item-icon-pack-range", re.compile(r"^item_icon_(\d+)-(\d+)\.swf$", re.I), None),
    ("item-icon-pack-before", re.compile(r"^item_icon_before_(\d+)\.swf$", re.I), None),
    ("skill", re.compile(r"^skill_?(\d+)\.swf$", re.I), "skill_{}"),
    ("weapon", re.compile(r"^wpn_?(\d+)\.swf$", re.I), "wpn_{}"),
    ("clothing", re.compile(r"^set_?(\d+)(?:_(\d+))?\.swf$", re.I), "set_{}"),
    ("back", re.compile(r"^back_?(\d+)\.swf$", re.I), "back_{}"),
    ("accessory", re.compile(r"^(?:acsy|accessory)_?(\d+)\.swf$", re.I), "acsy_{}"),
    ("hair", re.compile(r"^hair_?(\d+)(?:_(\d+))?\.swf$", re.I), "hair_{}"),
    ("item", re.compile(r"^item_?(\d+)\.swf$", re.I), "item_{}"),
    ("pet", re.compile(r"^pet_?(\d+)\.swf$", re.I), "pet_{}"),
    ("enemy", re.compile(r"^(?:ene|enemy)_?(\d+)\.swf$", re.I), "enemy_{}"),
    ("icon", re.compile(r"^icon_?(\d+)\.swf$", re.I), "icon_{}"),
)

LIBRARY_NAMES = {
    "data_library_en.swf": "data-library",
    "code_library.swf": "code-library",
    "client_library.swf": "client-library",
    "network_library.swf": "network-library",
}


def classify(name: str):
    lowered = name.lower()
    if lowered in LIBRARY_NAMES:
        return {
            "kind": "library",
            "source_id": LIBRARY_NAMES[lowered],
            "canonical_id": LIBRARY_NAMES[lowered],
        }

    for kind, pattern, formatter in PATTERNS:
        match = pattern.match(name)
        if not match:
            continue

        first = str(int(match.group(1)))

        if kind == "skill-icon-pack":
            return {
                "kind": "skill-icon-pack",
                "source_id": name[:-4],
                "canonical_id": f"skill_icon_{first}_to_{int(match.group(2))}",
            }

        if kind == "item-icon-pack-range":
            return {
                "kind": "item-icon-pack",
                "source_id": name[:-4],
                "canonical_id": f"item_icon_{first}_to_{int(match.group(2))}",
            }

        if kind == "item-icon-pack-before":
            return {
                "kind": "item-icon-pack",
                "source_id": name[:-4],
                "canonical_id": f"item_icon_before_{first}",
            }

        canonical = formatter.format(first)
        if match.lastindex and match.lastindex >= 2 and match.group(2) is not None:
            # Numeric source variants are preserved without guessing whether
            # they mean gender, stance, generation, or another client concern.
            canonical += "_variant_" + str(int(match.group(2)))

        return {
            "kind": kind,
            "source_id": name[:-4],
            "canonical_id": canonical,
        }

    return None

def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while chunk := handle.read(1024 * 1024):
            digest.update(chunk)
    return digest.hexdigest()


def scan(root: Path) -> dict:
    root = root.resolve(strict=True)
    if not root.is_dir():
        raise ValueError("asset root must be a directory")

    records = []
    ignored = 0
    seen = set()

    for path in sorted(root.rglob("*")):
        if path.is_symlink():
            raise ValueError(f"symlink refused: {path.relative_to(root)}")
        if not path.is_file():
            continue

        relative = path.relative_to(root).as_posix()
        if len(records) + ignored >= MAX_FILES:
            raise ValueError("asset file limit exceeded")

        if path.suffix.lower() != ".swf":
            ignored += 1
            continue

        size = path.stat().st_size
        if size <= 0 or size > MAX_FILE_BYTES:
            raise ValueError(f"invalid SWF size: {relative}")

        identity = classify(path.name)
        if identity is None:
            ignored += 1
            continue

        duplicate_key = (identity["kind"], identity["canonical_id"])
        duplicate = duplicate_key in seen
        seen.add(duplicate_key)

        records.append({
            **identity,
            "source_ref": relative,
            "bytes": size,
            "sha256": sha256(path),
            "duplicate_identity": duplicate,
            "visual_status": "unprocessed",
        })

    counts = {}
    for record in records:
        counts[record["kind"]] = counts.get(record["kind"], 0) + 1

    return {
        "schema_version": 1,
        "scan_type": "ninja-saga-swf-pack",
        "files_recognized": len(records),
        "files_ignored": ignored,
        "duplicate_identities": sum(1 for record in records if record["duplicate_identity"]),
        "counts": dict(sorted(counts.items())),
        "assets": records,
        "notes": [
            "Read-only inventory only.",
            "No binary was copied or published.",
            "No gameplay mechanics were inferred from SWF content.",
            "Recognition does not establish rights, authenticity, or runtime compatibility.",
        ],
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("root", type=Path)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()

    try:
        report = scan(args.root)
    except (OSError, ValueError) as exc:
        print(f"ASSET_SCAN_ERROR: {exc}", file=sys.stderr)
        return 2

    encoded = json.dumps(report, indent=2, sort_keys=True) + "\n"

    if args.output:
        output = args.output.resolve()
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(encoded, encoding="utf-8")
        print(f"ASSET_SCAN_OK {report['files_recognized']} -> {output}")
    else:
        sys.stdout.write(encoded)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
