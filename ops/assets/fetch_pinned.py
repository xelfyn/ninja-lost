#!/usr/bin/env python3
"""Fetch a pinned original-game asset starter pack into private runtime storage.

Safety / scope:
- exact repository + commit + path lock only;
- validates byte length and Git blob SHA-1;
- refuses redirects and non-GitHub hosts;
- writes only beneath an explicit fresh destination;
- never publishes, activates, executes or commits SWF files.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import sys
import urllib.parse
import urllib.request

MAX_TOTAL_BYTES = 64 * 1024 * 1024
ALLOWED_HOST = "raw.githubusercontent.com"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ValueError("redirect refused")


def git_blob_sha1(data: bytes) -> str:
    header = f"blob {len(data)}\0".encode("ascii")
    return hashlib.sha1(header + data).hexdigest()


def validate_lock(lock: dict) -> None:
    if lock.get("schema_version") != 1:
        raise ValueError("unsupported lock schema")

    repo = lock.get("repository")
    commit = lock.get("commit")
    files = lock.get("files")

    if not isinstance(repo, str) or "/" not in repo:
        raise ValueError("invalid repository")
    if not isinstance(commit, str) or len(commit) != 40 or any(c not in "0123456789abcdef" for c in commit):
        raise ValueError("invalid commit")
    if not isinstance(files, list) or not files or len(files) > 200:
        raise ValueError("invalid file list")

    total = 0
    seen = set()
    for entry in files:
        if not isinstance(entry, dict):
            raise ValueError("invalid file entry")

        path = entry.get("path")
        blob = entry.get("git_blob")
        size = entry.get("bytes")
        canonical = entry.get("canonical_id")
        kind = entry.get("kind")

        if not all(isinstance(v, str) and v for v in [path, blob, canonical, kind]):
            raise ValueError("invalid file identity")
        if path.startswith("/") or ".." in Path(path).parts or "\\" in path:
            raise ValueError("unsafe path")
        if len(blob) != 40 or any(c not in "0123456789abcdef" for c in blob):
            raise ValueError("invalid git blob")
        if not isinstance(size, int) or size <= 0 or size > 32 * 1024 * 1024:
            raise ValueError("invalid file size")
        key = (kind, canonical)
        if key in seen:
            raise ValueError("duplicate canonical identity")
        seen.add(key)
        total += size

    if total > MAX_TOTAL_BYTES:
        raise ValueError("locked pack too large")


def raw_url(repo: str, commit: str, path: str) -> str:
    owner, name = repo.split("/", 1)
    return (
        "https://raw.githubusercontent.com/"
        + urllib.parse.quote(owner, safe="")
        + "/"
        + urllib.parse.quote(name, safe="")
        + "/"
        + commit
        + "/"
        + urllib.parse.quote(path, safe="/")
    )


def fetch(lock_path: Path, destination: Path) -> dict:
    lock = json.loads(lock_path.read_text(encoding="utf-8"))
    validate_lock(lock)

    destination = destination.resolve()
    if destination.exists():
        raise ValueError("destination must not already exist")
    destination.mkdir(parents=True, mode=0o700)

    opener = urllib.request.build_opener(NoRedirect)
    manifest = {
        "schema_version": 1,
        "source": {
            "repository": lock["repository"],
            "commit": lock["commit"],
        },
        "files": [],
    }

    try:
        for entry in lock["files"]:
            url = raw_url(lock["repository"], lock["commit"], entry["path"])
            parsed = urllib.parse.urlparse(url)
            if parsed.scheme != "https" or parsed.hostname != ALLOWED_HOST:
                raise ValueError("unapproved source host")

            request = urllib.request.Request(
                url,
                headers={"User-Agent": "NinjaLost-Private-Asset-Intake/1"},
            )
            with opener.open(request, timeout=30) as response:
                if response.status != 200:
                    raise ValueError("asset request failed")
                data = response.read(entry["bytes"] + 1)

            if len(data) != entry["bytes"]:
                raise ValueError(f"size mismatch: {entry['path']}")
            if git_blob_sha1(data) != entry["git_blob"]:
                raise ValueError(f"blob mismatch: {entry['path']}")

            relative = Path(entry["path"])
            output = destination / relative
            output.parent.mkdir(parents=True, exist_ok=True)
            output.write_bytes(data)

            manifest["files"].append({
                **entry,
                "sha256": hashlib.sha256(data).hexdigest(),
                "local_path": relative.as_posix(),
                "status": "fetched-unprocessed",
            })

        (destination / "intake-manifest.json").write_text(
            json.dumps(manifest, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
        return manifest
    except Exception:
        # Leave no partial pack that could be mistaken for accepted intake.
        for path in sorted(destination.rglob("*"), reverse=True):
            if path.is_file() or path.is_symlink():
                path.unlink(missing_ok=True)
            elif path.is_dir():
                try:
                    path.rmdir()
                except OSError:
                    pass
        try:
            destination.rmdir()
        except OSError:
            pass
        raise


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--lock",
        type=Path,
        default=Path(__file__).with_name("source-lock.json"),
    )
    parser.add_argument("destination", type=Path)
    args = parser.parse_args()

    try:
        manifest = fetch(args.lock.resolve(strict=True), args.destination)
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"ASSET_FETCH_ERROR: {exc}", file=sys.stderr)
        return 2

    total = sum(entry["bytes"] for entry in manifest["files"])
    print(f"ASSET_FETCH_OK files={len(manifest['files'])} bytes={total}")
    print("Assets remain private/unprocessed; no publication or activation performed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
