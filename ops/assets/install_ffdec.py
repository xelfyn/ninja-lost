#!/usr/bin/env python3
"""Install the pinned FFDec release into a private runtime directory.

The installer:
- reads ops/assets/tool-lock.json;
- downloads only the locked GitHub release URL;
- validates byte length and SHA-256;
- safely extracts the archive;
- inventories every installed regular file with SHA-256;
- revalidates those installed bytes before any later reuse;
- never commits the tool or modifies application code.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import shutil
import sys
import urllib.parse
import urllib.request
import zipfile

ALLOWED_INITIAL_HOST = "github.com"
ALLOWED_REDIRECT_HOSTS = {
    "objects.githubusercontent.com",
    "release-assets.githubusercontent.com",
    "github-production-release-asset-2e65be.s3.amazonaws.com",
}
MAX_INSTALL_FILES = 5000
MAX_INSTALL_BYTES = 64 * 1024 * 1024


class GuardedRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        parsed = urllib.parse.urlparse(newurl)
        if parsed.scheme != "https" or parsed.hostname not in ALLOWED_REDIRECT_HOSTS:
            raise ValueError("unapproved redirect host")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def load_lock(path: Path) -> dict:
    data = json.loads(path.read_text(encoding="utf-8"))
    entry = data.get("tools", {}).get("ffdec")
    if data.get("schema_version") != 1 or not isinstance(entry, dict):
        raise ValueError("invalid tool lock")
    required = ["version", "url", "bytes", "sha256"]
    if any(key not in entry for key in required):
        raise ValueError("incomplete FFDec lock")
    if not isinstance(entry["bytes"], int) or entry["bytes"] <= 0 or entry["bytes"] > 64 * 1024 * 1024:
        raise ValueError("invalid FFDec size")
    if not isinstance(entry["sha256"], str) or len(entry["sha256"]) != 64:
        raise ValueError("invalid FFDec digest")
    parsed = urllib.parse.urlparse(entry["url"])
    if parsed.scheme != "https" or parsed.hostname != ALLOWED_INITIAL_HOST:
        raise ValueError("invalid FFDec source host")
    return entry


def safe_member(name: str) -> PurePosixPath:
    member = PurePosixPath(name)
    if member.is_absolute() or ".." in member.parts or not member.parts:
        raise ValueError("unsafe archive path")
    return member


def hash_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while chunk := handle.read(1024 * 1024):
            digest.update(chunk)
    return digest.hexdigest()


def installation_inventory(root: Path) -> list[dict]:
    root = root.resolve(strict=True)
    rows = []
    total = 0

    for path in sorted(root.rglob("*")):
        if path.name == "install.json":
            continue
        if path.is_symlink():
            raise ValueError("symlink in FFDec installation")
        if not path.is_file():
            continue

        size = path.stat().st_size
        total += size
        if len(rows) >= MAX_INSTALL_FILES or total > MAX_INSTALL_BYTES:
            raise ValueError("FFDec installation exceeded bounds")

        rows.append({
            "path": path.relative_to(root).as_posix(),
            "bytes": size,
            "sha256": hash_file(path),
        })

    if not rows or not any(row["path"] == "ffdec.jar" for row in rows):
        raise ValueError("FFDec installation missing ffdec.jar")

    return rows


def verify_existing_installation(destination: Path, entry: dict) -> Path:
    destination = destination.resolve(strict=True)
    marker = destination / "install.json"
    jar = destination / "ffdec.jar"

    if marker.is_symlink() or not marker.is_file() or jar.is_symlink() or not jar.is_file():
        raise ValueError("existing FFDec installation is incomplete")

    try:
        current = json.loads(marker.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise ValueError("existing FFDec marker is invalid") from exc

    if (
        current.get("version") != entry["version"]
        or current.get("archive_sha256") != entry["sha256"]
        or not isinstance(current.get("files"), list)
    ):
        raise ValueError("existing FFDec installation does not match lock")

    actual = installation_inventory(destination)
    if actual != current["files"]:
        raise ValueError("existing FFDec installed bytes do not match marker")

    return jar


def install(lock_path: Path, destination: Path) -> Path:
    entry = load_lock(lock_path)
    destination = destination.resolve()

    if destination.exists():
        return verify_existing_installation(destination, entry)

    destination.parent.mkdir(parents=True, exist_ok=True)
    temp_root = destination.with_name(destination.name + ".tmp")
    if temp_root.exists():
        shutil.rmtree(temp_root)
    temp_root.mkdir(mode=0o700)

    archive = temp_root / "ffdec.zip"
    opener = urllib.request.build_opener(GuardedRedirect)
    request = urllib.request.Request(
        entry["url"],
        headers={"User-Agent": "NinjaLost-Private-Asset-Tooling/1"},
    )

    try:
        digest = hashlib.sha256()
        total = 0
        with opener.open(request, timeout=60) as response, archive.open("wb") as out:
            while True:
                chunk = response.read(1024 * 1024)
                if not chunk:
                    break
                total += len(chunk)
                if total > entry["bytes"]:
                    raise ValueError("FFDec download exceeded locked size")
                digest.update(chunk)
                out.write(chunk)

        if total != entry["bytes"]:
            raise ValueError("FFDec size mismatch")
        if digest.hexdigest() != entry["sha256"]:
            raise ValueError("FFDec digest mismatch")

        extract_root = temp_root / "extract"
        extract_root.mkdir()
        with zipfile.ZipFile(archive) as zf:
            for info in zf.infolist():
                member = safe_member(info.filename)
                target = extract_root.joinpath(*member.parts)
                if info.is_dir():
                    target.mkdir(parents=True, exist_ok=True)
                    continue
                target.parent.mkdir(parents=True, exist_ok=True)
                with zf.open(info) as source, target.open("wb") as sink:
                    shutil.copyfileobj(source, sink)

        candidates = list(extract_root.rglob("ffdec.jar"))
        if len(candidates) != 1:
            raise ValueError("expected exactly one ffdec.jar in archive")

        final_root = temp_root / "final"
        shutil.copytree(candidates[0].parent, final_root)
        files = installation_inventory(final_root)
        (final_root / "install.json").write_text(
            json.dumps(
                {
                    "version": entry["version"],
                    "archive_sha256": entry["sha256"],
                    "source": entry["url"],
                    "files": files,
                },
                indent=2,
                sort_keys=True,
            )
            + "\n",
            encoding="utf-8",
        )

        destination.parent.mkdir(parents=True, exist_ok=True)
        final_root.rename(destination)
        return verify_existing_installation(destination, entry)
    finally:
        if temp_root.exists():
            shutil.rmtree(temp_root, ignore_errors=True)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--lock",
        type=Path,
        default=Path(__file__).with_name("tool-lock.json"),
    )
    parser.add_argument("destination", type=Path)
    args = parser.parse_args()

    try:
        jar = install(args.lock.resolve(strict=True), args.destination)
    except (OSError, ValueError, json.JSONDecodeError, zipfile.BadZipFile) as exc:
        print(f"FFDEC_INSTALL_ERROR: {exc}", file=sys.stderr)
        return 2

    print(f"FFDEC_INSTALL_OK {jar}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
