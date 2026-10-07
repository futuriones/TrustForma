#!/usr/bin/env python3
"""Fail when the installed Python packages differ from lms/tools/requirements.txt (see `make deps`).

requirements.txt is the lock written by `make lock` (pip-compile --generate-hashes): every package the tooling installs,
direct or pulled in by another, as `name==version \\` followed by its `--hash=sha256:...` lines. All of them are checked,
so a drifted indirect dependency is caught too.
"""
from __future__ import annotations

import re
import sys
from importlib import metadata
from pathlib import Path

REQ = Path(__file__).resolve().parent / "requirements.txt"
PIN_RE = re.compile(r"^([A-Za-z0-9_.-]+)==([A-Za-z0-9_.+!-]+)$")


def pins(text: str) -> tuple[dict[str, str], list[str]]:
    """({package: version}, problems) from a hashed lock file."""
    found: dict[str, str] = {}
    problems: list[str] = []
    current = None
    hashed: set[str] = set()
    for raw in text.splitlines():
        line = raw.split(" #", 1)[0].strip()
        if not line or line.startswith("#"):
            continue
        line = line.rstrip("\\").strip()
        if line.startswith("--hash="):
            if current:
                hashed.add(current)
            continue
        m = PIN_RE.match(line)
        if not m:
            problems.append(f"unpinned or malformed requirement: {line!r} (the lock is written by `make lock`)")
            current = None
            continue
        current = m.group(1)
        found[current] = m.group(2)
    problems += [f"{name}: no --hash in the lock (regenerate it with `make lock`)" for name in found if name not in hashed]
    return found, problems


def main() -> int:
    found, problems = pins(REQ.read_text(encoding="utf-8"))
    if not found:
        problems.append("requirements.txt lists no packages")
    for name, want in found.items():
        try:
            have = metadata.version(name)
        except metadata.PackageNotFoundError:
            problems.append(f"{name}: not installed (want {want})")
            continue
        if have != want:
            problems.append(f"{name}: installed {have}, pinned {want}")
    for p in problems:
        print(f"ERROR {p}", file=sys.stderr)
    if problems:
        print("Install the locked versions: `make venv`", file=sys.stderr)
        return 1
    print(f"OK   Python dependencies match lms/tools/requirements.txt ({len(found)} packages, hash-locked)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
