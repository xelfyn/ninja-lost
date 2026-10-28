#!/usr/bin/env python3
"""Install the pinned FFDec release into a private runtime directory.

The installer:
- reads ops/assets/tool-lock.json;
- downloads only the locked GitHub release URL;
- validates byte length and SHA-256;
- safely extracts the archive;
- never commits the tool or modifies application code;
- is idempotent when the existing installation marker matches.
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


def install(lock_path: Path, destination: Path) -> Path:
    entry = load_lock(lock_path)
    destination = destination.resolve()
    marker = destination / "install.json"
    jar = destination / "ffdec.jar"

    if marker.is_file() and jar.is_file():
        try:
            current = json.loads(marker.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            current = {}
        if (
            current.get("version") == entry["version"]
            and current.get("sha256") == entry["sha256"]
        ):
            return jar
        raise ValueError("existing FFDec installation does not match lock")

    if destination.exists():
        raise ValueError("destination must be empty or an exact existing installation")

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
                resolved_parent = target.parent.resolve()
                if not str(resolved_parent).startswith(str(extract_root.resolve())):
                    raise ValueError("archive path escaped destination")
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
        (final_root / "install.json").write_text(
            json.dumps(
                {
                    "version": entry["version"],
                    "sha256": entry["sha256"],
                    "source": entry["url"],
                },
                indent=2,
                sort_keys=True,
            )
            + "\n",
            encoding="utf-8",
        )
        destination.parent.mkdir(parents=True, exist_ok=True)
        final_root.rename(destination)
        return destination / "ffdec.jar"
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
