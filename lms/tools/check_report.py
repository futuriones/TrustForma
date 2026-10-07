#!/usr/bin/env python3
"""Check the compliance report read from stdin against the contract and pretty-print it (`make report`, `make e2e`).

Moodle's REST server answers HTTP 200 even for errors, with a body like {"exception": ..., "errorcode": "invalidtoken"}:
`curl -f` cannot see that, so the exit code has to come from the body. A real report is validated against
schema/compliance-report.v1.schema.json, the same file the consumer (the GRC portal's awareness-import) is tested with.

Exit codes: 0 a report that matches the contract, 1 Moodle returned an error, 2 the body is not JSON, 3 contract violation.
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from jsonschema import Draft202012Validator

SCHEMA = Path(__file__).resolve().parent / "schema" / "compliance-report.v1.schema.json"


def violations(report) -> list[str]:
    """Human-readable contract violations of a parsed report (empty when it matches)."""
    validator = Draft202012Validator(json.loads(SCHEMA.read_text(encoding="utf-8")))
    errors = sorted(validator.iter_errors(report), key=lambda e: list(e.absolute_path))
    return [f"{'/'.join(str(p) for p in e.absolute_path) or '(root)'}: {e.message}" for e in errors]


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--quiet", action="store_true", help="do not print the report (it holds personal data), only the verdict")
    args = ap.parse_args(argv)
    raw = sys.stdin.read()
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as e:
        print(f"ERROR the API did not answer with JSON ({e}): {raw[:200]!r}", file=sys.stderr)
        return 2
    if isinstance(data, dict) and "exception" in data:
        print(json.dumps(data, indent=4))
        print(f"ERROR Moodle refused the call: {data.get('errorcode', '?')}: {data.get('message', '')}", file=sys.stderr)
        return 1
    if not args.quiet:
        print(json.dumps(data, indent=4))
    problems = violations(data)
    if problems:
        print(f"ERROR the report does not match {SCHEMA.name} ({len(problems)} problem(s)):", file=sys.stderr)
        for p in problems[:15]:
            print(f"  - {p}", file=sys.stderr)
        return 3
    if args.quiet:
        print(f"OK   report matches {SCHEMA.name}: {len(data['users'])} user(s) listed, {data['totals']['enrolled']} active")
    return 0


if __name__ == "__main__":
    sys.exit(main())
