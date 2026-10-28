#!/usr/bin/env python3
"""Export reviewable web derivatives from one trusted SWF using pinned FFDec.

This adapter does not publish files to the Player Portal. It produces an
unreviewed extraction bundle containing FFDec exports plus a deterministic
manifest. A separate operator review/promotion step is required.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys

MAX_SWF_BYTES = 32 * 1024 * 1024
MAX_EXPORT_FILES = 5000
MAX_EXPORT_BYTES = 128 * 1024 * 1024
ALLOWED_OUTPUT_EXTENSIONS = {".png", ".webp", ".jpg", ".jpeg", ".csv", ".txt"}


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while chunk := handle.read(1024 * 1024):
            digest.update(chunk)
    return digest.hexdigest()


def validate_file(path: Path, extension: str | None = None, max_bytes: int | None = None) -> Path:
    path = path.resolve(strict=True)
    if path.is_symlink() or not path.is_file():
        raise ValueError("expected regular file")
    if extension and path.suffix.lower() != extension:
        raise ValueError(f"expected {extension} file")
    size = path.stat().st_size
    if size <= 0 or (max_bytes is not None and size > max_bytes):
        raise ValueError("file size outside allowed range")
    return path


def command_base(jar: Path) -> list[str]:
    return ["java", "-Xmx512m", "-jar", str(jar), "-cli"]


def build_commands(jar: Path, swf: Path, output: Path, profile: str) -> list[list[str]]:
    base = command_base(jar)
    commands = [
        base + ["-export", "symbolClass", str(output / "symbol-class"), str(swf)],
        base + ["-format", "image:png", "-export", "image", str(output / "images"), str(swf)],
        base + ["-format", "sprite:png", "-export", "sprite", str(output / "sprites"), str(swf)],
    ]

    if profile == "content-preview":
        commands.append(
            base
            + [
                "-format",
                "frame:png",
                "-select",
                "1",
                "-export",
                "frame",
                str(output / "frame-1"),
                str(swf),
            ]
        )
    elif profile != "icon-pack":
        raise ValueError("unknown export profile")

    return commands


def run_command(command: list[str], cwd: Path, timeout: int) -> None:
    result = subprocess.run(
        command,
        cwd=cwd,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        timeout=timeout,
        check=False,
    )
    log = result.stdout[-128 * 1024 :]
    if result.returncode != 0:
        raise ValueError(
            "FFDec export failed: "
            + log.decode("utf-8", errors="replace").replace("\n", " ")[:4000]
        )


def inventory(output: Path) -> list[dict]:
    records = []
    total = 0
    for path in sorted(output.rglob("*")):
        if path.is_symlink():
            raise ValueError("symlink produced by export")
        if not path.is_file() or path.name == "export-manifest.json":
            continue
        if path.suffix.lower() not in ALLOWED_OUTPUT_EXTENSIONS:
            continue
        size = path.stat().st_size
        total += size
        if len(records) >= MAX_EXPORT_FILES or total > MAX_EXPORT_BYTES:
            raise ValueError("export bundle exceeded limits")
        records.append(
            {
                "path": path.relative_to(output).as_posix(),
                "bytes": size,
                "sha256": sha256(path),
                "visual_status": "extracted-unreviewed",
            }
        )
    return records


def export(jar: Path, swf: Path, output: Path, profile: str, timeout: int) -> dict:
    jar = validate_file(jar, ".jar", 64 * 1024 * 1024)
    swf = validate_file(swf, ".swf", MAX_SWF_BYTES)
    output = output.resolve()

    if output.exists():
        raise ValueError("output must not already exist")
    output.mkdir(parents=True, mode=0o700)

    try:
        for command in build_commands(jar, swf, output, profile):
            run_command(command, output, timeout)

        files = inventory(output)
        manifest = {
            "schema_version": 1,
            "profile": profile,
            "source": {
                "name": swf.name,
                "bytes": swf.stat().st_size,
                "sha256": sha256(swf),
            },
            "files": files,
            "status": "extracted-unreviewed",
            "notes": [
                "No derivative is published automatically.",
                "Symbol/class matching still requires validation.",
                "Visual extraction does not define gameplay mechanics.",
            ],
        }
        (output / "export-manifest.json").write_text(
            json.dumps(manifest, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
        return manifest
    except Exception:
        shutil.rmtree(output, ignore_errors=True)
        raise


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--ffdec-jar", type=Path, required=True)
    parser.add_argument("--profile", choices=["icon-pack", "content-preview"], required=True)
    parser.add_argument("--timeout", type=int, default=60)
    parser.add_argument("swf", type=Path)
    parser.add_argument("output", type=Path)
    args = parser.parse_args()

    if args.timeout < 5 or args.timeout > 300:
        print("ASSET_EXPORT_ERROR: timeout outside allowed range", file=sys.stderr)
        return 2

    try:
        manifest = export(
            args.ffdec_jar,
            args.swf,
            args.output,
            args.profile,
            args.timeout,
        )
    except (OSError, ValueError, subprocess.SubprocessError) as exc:
        print(f"ASSET_EXPORT_ERROR: {exc}", file=sys.stderr)
        return 2

    print(f"ASSET_EXPORT_OK files={len(manifest['files'])} profile={manifest['profile']}")
    print("Derivatives remain unreviewed and unpublished.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
