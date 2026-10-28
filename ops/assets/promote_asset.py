#!/usr/bin/env python3
"""Promote one reviewed web derivative into Ninja Lost's private asset registry.

This command never reads gameplay values from artwork and never activates content.
It only copies a reviewed image derivative into the configured web-asset root and
updates manifest v1 atomically.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import sys
import tempfile

KIND_RE = re.compile(r"\A[a-z][a-z0-9_-]{0,31}\Z")
SOURCE_ID_RE = re.compile(r"\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\Z")
ALLOWED_EXTENSIONS = {".png", ".webp", ".jpg", ".jpeg"}
ALLOWED_STATUSES = {"available", "verified"}
MAX_IMAGE_BYTES = 16 * 1024 * 1024


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while chunk := handle.read(1024 * 1024):
            digest.update(chunk)
    return digest.hexdigest()


def image_signature(path: Path, suffix: str) -> bool:
    with path.open("rb") as handle:
        header = handle.read(12)

    if suffix == ".png":
        return header.startswith(b"\x89PNG\r\n\x1a\n")
    if suffix in {".jpg", ".jpeg"}:
        return header.startswith(b"\xff\xd8\xff")
    if suffix == ".webp":
        return len(header) >= 12 and header[:4] == b"RIFF" and header[8:12] == b"WEBP"
    return False


def contained_file(path: Path, root: Path) -> Path:
    if path.is_symlink():
        raise ValueError("symlink derivative refused")

    resolved_root = root.resolve(strict=True)
    resolved = path.resolve(strict=True)

    if not resolved.is_file():
        raise ValueError("derivative must be a file")

    try:
        resolved.relative_to(resolved_root)
    except ValueError as exc:
        raise ValueError("derivative must stay inside extraction root") from exc

    return resolved


def read_manifest(path: Path) -> dict:
    if not path.exists():
        return {"schema_version": 1, "assets": {}}
    if path.is_symlink() or not path.is_file():
        raise ValueError("manifest path must be a regular file")

    data = json.loads(path.read_text(encoding="utf-8"))
    if (
        not isinstance(data, dict)
        or data.get("schema_version") != 1
        or not isinstance(data.get("assets"), dict)
    ):
        raise ValueError("unsupported asset manifest")
    return data


def atomic_json(path: Path, value: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(
        "w", encoding="utf-8", dir=path.parent, prefix=".manifest-", delete=False
    ) as handle:
        temp = Path(handle.name)
        json.dump(value, handle, ensure_ascii=False, indent=2, sort_keys=True)
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temp, path)


def atomic_copy(source: Path, destination: Path) -> None:
    destination.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(
        "wb", dir=destination.parent, prefix=".asset-", delete=False
    ) as handle:
        temp = Path(handle.name)
        with source.open("rb") as incoming:
            shutil.copyfileobj(incoming, handle, 1024 * 1024)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temp, destination)


def promote(
    *,
    extraction_root: Path,
    derivative: Path,
    public_root: Path,
    manifest_path: Path,
    kind: str,
    source_id: str,
    source_type: str,
    source_ref: str,
    visual_status: str,
    replace: bool = False,
) -> dict:
    if not KIND_RE.fullmatch(kind):
        raise ValueError("invalid kind")
    if not SOURCE_ID_RE.fullmatch(source_id):
        raise ValueError("invalid source id")
    if visual_status not in ALLOWED_STATUSES:
        raise ValueError("visual status must be available or verified")
    if source_type not in {"operator-import", "ninja-saga-swf", "metadata-pack"}:
        raise ValueError("unsupported source type")
    if (
        not source_ref
        or len(source_ref) > 255
        or "\x00" in source_ref
        or source_ref.startswith("/")
        or "\\" in source_ref
        or ".." in Path(source_ref).parts
    ):
        raise ValueError("invalid source reference")

    selected = contained_file(derivative, extraction_root)
    suffix = selected.suffix.lower()
    if suffix not in ALLOWED_EXTENSIONS:
        raise ValueError("unsupported web derivative extension")

    size = selected.stat().st_size
    if size <= 0 or size > MAX_IMAGE_BYTES:
        raise ValueError("invalid web derivative size")
    if not image_signature(selected, suffix):
        raise ValueError("web derivative signature mismatch")

    manifest = read_manifest(manifest_path)
    family = manifest["assets"].setdefault(kind, {})
    existing = family.get(source_id)
    if existing is not None and not replace:
        raise ValueError("asset identity already exists; use --replace after review")

    extension = ".jpg" if suffix == ".jpeg" else suffix
    safe_id = re.sub(r"[^a-zA-Z0-9_.-]+", "_", source_id)
    web_path = f"{kind}/{safe_id}{extension}"
    destination_root = public_root.resolve()
    destination = destination_root / web_path

    if destination.exists() and destination.is_symlink():
        raise ValueError("destination symlink refused")

    derivative_hash = sha256(selected)
    atomic_copy(selected, destination)

    record = {
        "source_id": source_id,
        "source_type": source_type,
        "source_ref": source_ref,
        "web_path": web_path,
        "visual_status": visual_status,
        "derivative_sha256": derivative_hash,
        "derivative_bytes": size,
    }
    family[source_id] = record
    atomic_json(manifest_path, manifest)

    return {
        "kind": kind,
        **record,
        "replaced": existing is not None,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--extraction-root", required=True, type=Path)
    parser.add_argument("--derivative", required=True, type=Path)
    parser.add_argument("--public-root", required=True, type=Path)
    parser.add_argument("--manifest", required=True, type=Path)
    parser.add_argument("--kind", required=True)
    parser.add_argument("--source-id", required=True)
    parser.add_argument("--source-type", default="ninja-saga-swf")
    parser.add_argument("--source-ref", required=True)
    parser.add_argument("--status", default="available")
    parser.add_argument("--replace", action="store_true")
    args = parser.parse_args()

    try:
        result = promote(
            extraction_root=args.extraction_root,
            derivative=args.derivative,
            public_root=args.public_root,
            manifest_path=args.manifest,
            kind=args.kind,
            source_id=args.source_id,
            source_type=args.source_type,
            source_ref=args.source_ref,
            visual_status=args.status,
            replace=args.replace,
        )
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"ASSET_PROMOTE_ERROR: {exc}", file=sys.stderr)
        return 2

    print("ASSET_PROMOTE_OK " + json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
