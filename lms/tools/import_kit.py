#!/usr/bin/env python3
"""Import the INCIBE "Kit de concienciación" zip (dropped in upload/) into the kit folder (course.yaml `source_root`, i.e. kit/).

Default is a DRY RUN: the zip is validated and streamed once (nothing is extracted), and a report says what would be added,
removed or changed, grouped by course module, plus what to do next. `--apply` extracts into upload/.staging and swaps it in
for kit/ (the previous kit is kept once in upload/.previous-kit). Problems (a module folder the course needs is missing or
ambiguous, a test PDF vanished) refuse `--apply` unless `--force`: kit/ is only a mirror, `make validate` stays the gate.

Exit codes: 0 nothing to do / applied, 1 problems found, 2 usage error or unsafe zip, 3 dry run found changes,
4 unexpected I/O or data error (kit/ is unchanged: a swap that fails half-way puts the old kit back).
The phishing/USB simulation tooling in the zip (Ataques_dirigidos/, Manual_Gophish/) is never extracted, at any depth.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
import re
import sys
import tempfile
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import build as b  # noqa: E402  (course.yaml loading, module discovery and path confinement are shared with the build)
import yaml  # noqa: E402

EXCLUDED_DIRS = {"ataques_dirigidos", "manual_gophish", "__macosx"}
EXCLUDED_FILES = {"thumbs.db", ".ds_store", "desktop.ini"}
MAX_TOTAL_BYTES = 3 * 1024**3
MAX_ENTRY_RATIO = 200  # uncompressed/compressed, only checked for entries above 10 MB (the kit's PNG/PPTX/PDF sit near 1-3)
IMPORT_META = "IMPORT.json"
STAGING, PREVIOUS, REPORT = ".staging", ".previous-kit", "import-report.md"
OUTGOING = ".outgoing-kit"  # the current kit while the new one is being swapped in; becomes PREVIOUS once the swap succeeded


class ImportError_(Exception):
    """The zip is unsafe or unusable (exit 2)."""


def sha256_stream(f, on_chunk=None) -> tuple[str, int]:
    h, n = hashlib.sha256(), 0
    for chunk in iter(lambda: f.read(1 << 20), b""):
        h.update(chunk)
        n += len(chunk)
        if on_chunk:
            on_chunk(chunk)
    return h.hexdigest(), n


# ---------------------------------------------------------------- zip listing
def decode_name(info: zipfile.ZipInfo) -> str:
    """zipfile already decodes UTF-8 names (flag 0x800) and everything else as CP437 (what INCIBE's zip uses: 0xA2 = 'ó').
    A zip without the flag whose raw bytes are valid UTF-8 is treated as UTF-8 (some tools forget the flag)."""
    name = info.filename
    if not (info.flag_bits & 0x800) and not name.isascii():
        try:
            name = name.encode("cp437").decode("utf-8")
        except (UnicodeEncodeError, UnicodeDecodeError):
            pass
    return b.nfc(name)


def list_entries(zf: zipfile.ZipFile) -> tuple[dict[str, zipfile.ZipInfo], dict[str, int]]:
    """Validate every entry and return {relative posix path: info} for the files to import, plus skipped counts per reason."""
    parsed = []
    for info in zf.infolist():
        name = decode_name(info).replace("\\", "/")
        if name.startswith("/") or re.match(r"^[A-Za-z]:", name) or "\x00" in name:
            raise ImportError_(f"unsafe path in zip (absolute): {name!r}")
        parts = [p for p in name.split("/") if p not in ("", ".")]
        if ".." in parts:
            raise ImportError_(f"unsafe path in zip (..): {name!r}")
        if (info.external_attr >> 16) & 0o170000 == 0o120000:
            raise ImportError_(f"symlink in zip: {name!r}")
        if parts:
            parsed.append((info, parts))
    tops = {parts[0] for _, parts in parsed}
    strip = len(tops) == 1 and all(len(parts) > 1 or info.is_dir() for info, parts in parsed)
    entries: dict[str, zipfile.ZipInfo] = {}
    seen: dict[str, str] = {}
    skipped: dict[str, int] = {}
    total = 0
    for info, parts in parsed:
        parts = parts[1:] if strip else parts
        if not parts or info.is_dir():
            continue
        rel = "/".join(parts)
        # At ANY depth: a zip with an extra file next to its top folder is not stripped, and the tooling would then sit one
        # level down (kit_concienciacion/Ataques_dirigidos/Password.txt) and slip past a first-component check.
        excluded = next((p for p in parts if p.casefold() in EXCLUDED_DIRS), None)
        if excluded is not None:
            skipped[excluded] = skipped.get(excluded, 0) + 1
            continue
        if parts[-1].casefold() in EXCLUDED_FILES:
            skipped[parts[-1]] = skipped.get(parts[-1], 0) + 1
            continue
        ident = rel.casefold()
        if ident in seen:
            raise ImportError_(f"duplicate path in zip: {rel!r} and {seen[ident]!r}")
        seen[ident] = rel
        if info.file_size > 10 * 1024**2 and info.file_size / max(info.compress_size, 1) > MAX_ENTRY_RATIO:
            raise ImportError_(f"suspicious compression ratio (zip bomb?): {rel}")
        total += info.file_size
        entries[rel] = info
    if total > MAX_TOTAL_BYTES:
        raise ImportError_(f"zip expands to {total / 1024**3:.1f} GiB (limit {MAX_TOTAL_BYTES // 1024**3} GiB)")
    if not entries:
        raise ImportError_("the zip contains no importable files")
    return entries, skipped


def scan(zf: zipfile.ZipFile, entries: dict[str, zipfile.ZipInfo], dest: Path | None = None) -> dict[str, dict]:
    """Stream each entry once: sha256 (and write it under `dest` when given). Bytes must match the declared size."""
    result = {}
    for rel, info in sorted(entries.items()):
        out = None
        if dest is not None:
            target = dest / rel
            if os.path.commonpath([os.path.realpath(target.parent), os.path.realpath(dest)]) != os.path.realpath(dest):
                raise ImportError_(f"refusing to write outside the staging folder: {rel}")
            target.parent.mkdir(parents=True, exist_ok=True)
            out = target.open("wb")
        try:
            with zf.open(info) as src:
                sha, size = sha256_stream(src, out.write if out else None)
        finally:
            if out:
                out.close()
        if size != info.file_size:
            raise ImportError_(f"{rel}: expanded to {size} bytes, zip declares {info.file_size}")
        result[rel] = {"sha256": sha, "size": size}
    return result


def scan_dir(root: Path) -> dict[str, dict]:
    result = {}
    if not root.is_dir():
        return result
    for p in sorted(root.rglob("*")):
        if not p.is_file() or p.name == IMPORT_META or p.name.casefold() in EXCLUDED_FILES:
            continue
        with p.open("rb") as f:
            sha, size = sha256_stream(f)
        result[b.nfc(p.relative_to(root).as_posix())] = {"sha256": sha, "size": size}
    return result


# ---------------------------------------------------------------- analysis
def classify(rel: str, areas: list[tuple[str, str]]) -> str:
    for prefix, label in areas:
        if rel == prefix or rel.startswith(prefix + "/"):
            return label
    return "Kit content the course does not use"


def analyse(course: dict, course_dir: Path, incoming: dict[str, dict], current: dict[str, dict]) -> dict:
    """Compare incoming vs current and check the incoming tree against course.yaml, without touching the disk."""
    areas = [(b.nfc(m["folder"]), m["key"]) for m in course["modules"]]
    w = course["welcome"]
    areas += [(b.nfc(w[k]), label) for k, label in (("posters", "Welcome: posters"), ("trypticos", "Welcome: leaflets")) if w.get(k)]
    areas.sort(key=lambda a: -len(a[0]))

    added = sorted(set(incoming) - set(current))
    removed = sorted(set(current) - set(incoming))
    changed = sorted(p for p in set(incoming) & set(current) if incoming[p]["sha256"] != current[p]["sha256"])

    problems: list[str] = []
    notes: list[str] = []
    # Zero-byte shadow of the incoming tree: build.discover_module then applies exactly the build's rules (globs, overrides).
    with tempfile.TemporaryDirectory(prefix="kit-shadow-") as tmp:
        shadow = Path(tmp)
        for rel in incoming:
            (shadow / rel).parent.mkdir(parents=True, exist_ok=True)
            (shadow / rel).touch()
        for m in course["modules"]:
            try:
                b.discover_module(shadow / m["folder"], m.get("files", {}), m["key"], shadow)
            except b.BuildError as e:
                problems.append(str(e).replace(str(shadow) + "/", ""))
        for m in course["modules"]:
            bank_path = course_dir / m["questions"]
            bank = yaml.safe_load(bank_path.read_text(encoding="utf-8")) if bank_path.is_file() else None
            src = (bank or {}).get("source")
            if not src:
                continue
            rel = b.nfc(src["file"])
            if rel not in incoming:
                problems.append(f"{m['key']}: test PDF {rel} is not in the zip (renamed or removed): update questions/{m['key']}.yaml `source.file`")
            elif incoming[rel]["sha256"] != src["sha256"]:
                notes.append(f"{m['key']}: test PDF changed ({rel}): run `make extract FORCE=1` and review `git diff courses/`")
    for m in (w.get("posters"), w.get("trypticos")):
        if m and not any(p.startswith(b.nfc(m) + "/") for p in incoming):
            problems.append(f"welcome folder {m}/ is missing or empty in the zip")

    known = {folder.split("/")[-1] for folder, _ in areas}
    new_dirs = sorted({"/".join(p.split("/")[:2]) for p in incoming if p.startswith("RecursosFormativos/") and p.count("/") >= 2
                       and p.split("/")[1] not in known})
    for d in new_dirs:
        notes.append(f"new module folder {d}/ is not in course.yaml: add ONE entry to `modules:` with the next free key (AGENTS.md §5)")
    return {"areas": areas, "added": added, "removed": removed, "changed": changed, "problems": problems, "notes": notes}


def render_report(zip_name: str, zip_sha: str, result: dict, incoming: dict, skipped: dict, applied: bool | None) -> str:
    areas = result["areas"]
    lines = ["# Kit import report", "",
             f"- zip: `{zip_name}` (sha256 `{zip_sha[:16]}…`)",
             f"- files in zip to import: {len(incoming)}; skipped: " + (", ".join(f"{k} ×{v}" for k, v in sorted(skipped.items())) or "none"),
             f"- added {len(result['added'])}, removed {len(result['removed'])}, changed {len(result['changed'])}",
             "- state: " + {None: "dry run (nothing written)", True: "APPLIED", False: "NOT applied"}[applied], ""]
    for title, key in (("Added", "added"), ("Changed", "changed"), ("Removed", "removed")):
        if not result[key]:
            continue
        lines += [f"## {title}", ""]
        groups: dict[str, list[str]] = {}
        for p in result[key]:
            groups.setdefault(classify(p, areas), []).append(p)
        for label in sorted(groups):
            lines.append(f"**{label}**")
            lines += [f"- {p}" for p in groups[label]]
            lines.append("")
    if result["problems"]:
        lines += ["## Problems (block --apply unless FORCE=1)", ""] + [f"- {p}" for p in result["problems"]] + [""]
    if result["notes"]:
        lines += ["## Action needed after applying", ""] + [f"- {n}" for n in result["notes"]] + [""]
    lines += ["## Next steps", ""]
    if not (result["added"] or result["removed"] or result["changed"]):
        lines.append("- Nothing differs from the current kit.")
    else:
        lines += ["1. `make import-kit APPLY=1` (if the report is what you expect).",
                  "2. If test PDFs changed: `make extract FORCE=1`, review `git diff courses/*/questions`; never reuse a question key (retire it).",
                  "3. `make validate test`, then `make build START=YYYY-MM-DD` and `make plan START=YYYY-MM-DD`; read the plan.",
                  "4. If the plan is `blocked`, make the change in the NEXT cycle. Otherwise `make backup` and `make deploy`, then `make e2e`.",
                  "See upload/README.md for the full procedure."]
    return "\n".join(lines) + "\n"


# ---------------------------------------------------------------- apply
def reset_staging(upload: Path) -> Path:
    staging = upload / STAGING
    if staging.exists():
        b.remove_child_tree(staging, upload)
    staging.mkdir()
    return staging


def recover_interrupted_swap(kit: Path, upload: Path) -> None:
    """Finish or undo a swap that was cut short (crash, power loss) between its renames, before anything else is read.
    upload/.outgoing-kit is the kit that was current when that swap began."""
    outgoing = upload / OUTGOING
    if not outgoing.exists():
        return
    if kit.exists():  # the new kit made it into place: only the hand-over of the previous copy was left undone
        _keep_as_previous(outgoing, upload)
        print(f"NOTE an earlier import was interrupted after the new kit was in place: kept its predecessor as upload/{PREVIOUS}", file=sys.stderr)
    else:  # the new kit never arrived: the old one is still the current kit
        outgoing.rename(kit)
        print("NOTE an earlier import was interrupted before the new kit was in place: the previous kit was put back", file=sys.stderr)


def _keep_as_previous(outgoing: Path, upload: Path) -> None:
    previous = upload / PREVIOUS
    if previous.exists():
        b.remove_child_tree(previous, upload)
    outgoing.rename(previous)


def swap_in(staging: Path, kit: Path, upload: Path, zip_name: str, zip_sha: str, files: dict[str, dict]) -> None:
    """staging -> kit, keeping exactly one previous kit in upload/.previous-kit (renames only: same filesystem, near-instant).

    The previous copy is only replaced AFTER the new kit is in place, and a rename that fails (a file held open by another program is enough)
    puts the current kit back: there is never a moment without a kit and without a way back."""
    (staging / IMPORT_META).write_text(json.dumps({
        "zip": zip_name, "zip_sha256": zip_sha, "imported_at": dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds"),
        "files": {p: v["sha256"] for p, v in sorted(files.items())}}, indent=1, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
    outgoing = upload / OUTGOING
    had_kit = kit.exists()
    if had_kit:
        kit.rename(outgoing)
    try:
        staging.rename(kit)
    except OSError:
        if had_kit and not kit.exists():
            outgoing.rename(kit)
        raise
    if had_kit:
        _keep_as_previous(outgoing, upload)


def find_zip(upload: Path, wanted: Path | None) -> Path:
    if wanted is not None:
        p = wanted.resolve()
        if p.parent != upload.resolve() or p.suffix.lower() != ".zip" or not p.is_file():
            raise ImportError_(f"ZIP must be an existing .zip directly inside {upload}/")
        return p
    found = sorted(p for p in upload.glob("*") if p.suffix.lower() == ".zip" and p.is_file())
    if len(found) != 1:
        raise ImportError_(f"expected exactly one .zip in {upload}/, found {len(found)}: " + (", ".join(p.name for p in found) or "none")
                           + ". Download kit_concienciacion.zip from the INCIBE page and copy it there (see upload/README.md).")
    return found[0]


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--repo-root", type=Path, default=Path("."))
    ap.add_argument("--course", default="concienciacion")
    ap.add_argument("--zip", type=Path, help="zip inside upload/ (default: the only .zip there)")
    ap.add_argument("--apply", action="store_true", help="replace the kit folder with the zip contents (default: dry run)")
    ap.add_argument("--force", action="store_true", help="apply even if the report lists problems")
    args = ap.parse_args(argv)

    repo = args.repo_root.resolve()
    upload = repo / "upload"
    staging: Path | None = None
    try:
        if not b.COURSE_ID_RE.match(args.course):
            raise ImportError_(f"invalid course id {args.course!r}")
        course_file = repo / "courses" / args.course / "course.yaml"
        if not course_file.is_file():
            raise ImportError_(f"course not found: {course_file}")
        course = b.load_yaml(course_file, "course.schema.json", "course")
        kit = b.confine(course_file.parent / course["source_root"], repo, "source_root", "the repository")
        if kit == repo or kit == upload.resolve():
            raise ImportError_(f"source_root must be a dedicated kit folder, not {kit}")
        upload.mkdir(exist_ok=True)
        recover_interrupted_swap(kit, upload)
        if kit.exists() and not kit.is_dir():
            raise ImportError_(f"source_root is not a folder: {kit}")
        zip_path = find_zip(upload, args.zip)
        with zipfile.ZipFile(zip_path) as zf:
            entries, skipped = list_entries(zf)
            with zip_path.open("rb") as raw:
                zip_sha = sha256_stream(raw)[0]
            current = scan_dir(kit)
            # --apply REPLACES the folder source_root points at. That folder must be a kit: one this tool made (it carries
            # IMPORT.json), an empty or missing one, or (first adoption of a hand-copied kit) one that already holds a module
            # folder of the course. A source_root mistyped as `.` or `../../lms` must not get a course or the tooling swapped away.
            module_dirs = [b.nfc(m["folder"]) + "/" for m in course["modules"]]
            if args.apply and current and not (kit / IMPORT_META).is_file() and not any(p.startswith(d) for p in current for d in module_dirs):
                raise ImportError_(f"{kit} has content but neither {IMPORT_META} nor any module folder of the course: it does not "
                                   f"look like a kit, so --apply will not replace it. Point source_root at a dedicated kit folder")
            # With --apply the zip is extracted (and hashed) into staging first, so what is applied is exactly what is reported
            # and a refused apply leaves kit/ untouched. A dry run only hashes.
            staging = reset_staging(upload) if args.apply else None
            incoming = scan(zf, entries, staging)
            result = analyse(course, course_file.parent, incoming, current)
            changes = bool(result["added"] or result["removed"] or result["changed"])
            meta = kit / IMPORT_META
            recorded = json.loads(meta.read_text(encoding="utf-8")).get("zip_sha256") if meta.is_file() else None

            applied: bool | None = None
            if args.apply:
                applied = not result["problems"] or args.force
                if applied and (changes or recorded != zip_sha):  # unchanged kit + same zip: nothing to swap
                    swap_in(staging, kit, upload, zip_path.name, zip_sha, incoming)
            report = render_report(zip_path.name, zip_sha, result, incoming, skipped, applied)
    except (ImportError_, zipfile.BadZipFile, b.BuildError) as e:
        print(f"ERROR {e}", file=sys.stderr)
        return 2
    except (OSError, json.JSONDecodeError, KeyError) as e:
        # Disk full, a file held open during the swap, a corrupt IMPORT.json, a question bank without source.file...
        # Not "problems in the report" (1) and not "unsafe zip" (2): say what broke; kit/ is as it was.
        print(f"ERROR unexpected {type(e).__name__}: {e}. Nothing was imported: kit/ is unchanged.", file=sys.stderr)
        return 4
    finally:
        if staging is not None and staging.exists():  # also after a failure: never leave a half-extracted tree behind
            b.remove_child_tree(staging, upload, ignore_errors=True)
    (upload / REPORT).write_text(report, encoding="utf-8", newline="\n")
    print(report, end="")
    print(f"(report also saved to upload/{REPORT})")
    if result["problems"]:
        return 0 if args.apply and args.force else 1
    if args.apply:
        return 0
    return 3 if changes else 0


if __name__ == "__main__":
    sys.exit(main())
