#!/usr/bin/env python3
"""Bootstrap question banks from the INCIBE `Test_evaluacion/*.pdf` files.

Reads each test PDF with `pdftotext -layout` (poppler), parses the 10 multiple-choice questions and the
"SOLUCIONES" answer key, and writes one reviewed-by-a-human YAML per module (see AGENTS.md §8 for the
quirks this handles). Run via `make extract`; after the first human review the YAML is the source of truth.

Usage (from the repo root):
    python3 lms/tools/extract_tests.py \
        --glob "RecursosFormativos/*/Test_evaluacion/*.pdf" \
        --out courses/concienciacion/questions --report build/extract_review.md
"""
from __future__ import annotations

import argparse
import hashlib
import re
import subprocess
import sys
from pathlib import Path

import yaml

OPTION_LETTERS = "abcd"
# Options whose meaning depends on their position/siblings must never be shuffled by Moodle.
# Anchored to the option start on purpose: "ambas partes" / "no hay ninguna obligación" are ordinary prose.
# Any «a»–«d» inside an option refers to a sibling by letter, so the option order must stay fixed.
NO_SHUFFLE_RE = re.compile(r"^(todas|ninguna|ambas)\b|\banteriores\b|«[a-d]»", re.IGNORECASE)
# A lone consonant at a line end is a word split across lines ("de d" + "escargas"), not a word.
# Real one-letter Spanish words (a, e, o, u, y) are deliberately not in this class.
SPLIT_WORD_RE = re.compile(r"(?<=\s)([b-df-hj-np-tv-xz])$", re.IGNORECASE)
QUESTION_RE = re.compile(r"^\s*(\d{1,2})\.\s*(.*)$")
OPTION_RE = re.compile(r"^\s*([a-d])\)\s*(.*)$")
ANSWER_RE = re.compile(r"^\s*(\d{1,2})\s+([A-Da-d])\s*$")
BODY_START = "Selecciona para cada pregunta"
SOLUTIONS_RE = re.compile(r"^\s*SOLUCIONES\s*$")
MIN_BODY_INDENT = 5  # question/option lines are indented; running headers/footers sit at column 0


class ParseError(ValueError):
    pass


def pdf_to_text(pdf: Path) -> str:
    # UTF-8 on both ends, said explicitly: the default of pdftotext and of Python's `text=True` both follow the locale, and a
    # C/POSIX locale (cron, a bare container) would turn every accent of the questions into mojibake or a decode error.
    out = subprocess.run(
        ["pdftotext", "-layout", "-enc", "UTF-8", str(pdf), "-"], check=True, capture_output=True, encoding="utf-8"
    )
    return out.stdout.replace("\f", "\n")


def _clean(s: str) -> str:
    return re.sub(r"\s+", " ", s).strip()


def _join(parts: list[str], notes: list[str]) -> str:
    """Join wrapped lines with a space, repairing words split across lines."""
    text = ""
    for raw in parts:
        piece = _clean(raw)
        if not piece:
            continue
        if text and SPLIT_WORD_RE.search(text) and piece[:1].islower():
            notes.append(f"joined split word: '…{text[-6:]}' + '{piece[:8]}…'")
            text = text + piece
        else:
            text = f"{text} {piece}".strip()
    return text


def parse_test_text(text: str) -> dict:
    """Parse pdftotext output into {'questions': [...], 'notes': [...]}. Raises ParseError if malformed."""
    lines = text.splitlines()
    start = next((i for i, line in enumerate(lines) if BODY_START in line), None)
    sol = next((i for i, line in enumerate(lines) if SOLUTIONS_RE.match(line)), None)
    if start is None or sol is None or sol < start:
        raise ParseError("could not locate the test body and the SOLUCIONES section")

    answers = {}
    for line in lines[sol + 1 :]:
        m = ANSWER_RE.match(line)
        if m:
            answers[int(m.group(1))] = m.group(2).lower()

    notes: list[str] = []
    questions: list[dict] = []
    cur_q = None  # dict with 'stem' parts and 'options' parts
    cur_target: list[str] | None = None
    for line in lines[start + 1 : sol]:
        if not line.strip():
            continue
        indent = len(line) - len(line.lstrip(" \t"))
        if indent < MIN_BODY_INDENT:  # running header / footer
            continue
        qm, om = QUESTION_RE.match(line), OPTION_RE.match(line)
        if qm and int(qm.group(1)) == len(questions) + 1:
            cur_q = {"number": int(qm.group(1)), "stem": [qm.group(2)], "options": {}}
            questions.append(cur_q)
            cur_target = cur_q["stem"]
        elif om and cur_q is not None and om.group(1) == OPTION_LETTERS[len(cur_q["options"])]:
            cur_target = [om.group(2)]
            cur_q["options"][om.group(1)] = cur_target
        elif cur_target is not None:
            cur_target.append(line)

    result = []
    for q in questions:
        n = q["number"]
        stem = _join(q["stem"], notes)
        options = {k: _join(v, notes) for k, v in q["options"].items()}
        if n not in answers:
            raise ParseError(f"question {n}: no answer in SOLUCIONES")
        result.append(
            {
                "number": n,
                "text": stem,
                "options": options,
                "answer": answers[n],
                "shuffle": not any(NO_SHUFFLE_RE.search(o) for o in options.values()),
            }
        )
    return {"questions": result, "notes": notes}


def validate(questions: list[dict], expected: int = 10) -> list[str]:
    """Hard errors (raise) and soft warnings (returned) for a parsed test."""
    if len(questions) != expected:
        raise ParseError(f"expected {expected} questions, parsed {len(questions)}")
    warnings = []
    for q in questions:
        n = q["number"]
        if list(q["options"]) != list(OPTION_LETTERS):
            raise ParseError(f"question {n}: options are {list(q['options'])}, expected a-d")
        if any(not v for v in [q["text"], *q["options"].values()]):
            raise ParseError(f"question {n}: empty text or option")
        if q["answer"] not in OPTION_LETTERS:
            raise ParseError(f"question {n}: invalid answer {q['answer']!r}")
        if not re.search(r"[:?.]$", q["text"]):
            warnings.append(f"Q{n}: stem does not end in punctuation: '…{q['text'][-30:]}'")
        if len(set(q["options"].values())) != 4:
            warnings.append(f"Q{n}: duplicate option texts")
        for k, v in q["options"].items():
            if re.search(r"[�­]", v):
                warnings.append(f"Q{n}{k}: odd character in option text")
    return warnings


def module_key(pdf: Path) -> str:
    m = re.match(r"^(\d{2})_", pdf.name)
    if not m:
        raise ParseError(f"cannot derive module key from file name {pdf.name!r} (expected NN_*.pdf)")
    return f"M{m.group(1)}"


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def build_module(pdf: Path, repo_root: Path) -> tuple[dict, list[str]]:
    key = module_key(pdf)
    parsed = parse_test_text(pdf_to_text(pdf))
    warnings = parsed["notes"] + validate(parsed["questions"])
    doc = {
        "module": key,
        "source": {"file": pdf.resolve().relative_to(repo_root.resolve()).as_posix(), "sha256": sha256(pdf)},
        "questions": [
            {
                "key": f"{key}-Q{q['number']:02d}",
                "text": q["text"],
                "options": q["options"],
                "answer": q["answer"],
                "shuffle": q["shuffle"],
            }
            for q in parsed["questions"]
        ],
    }
    return doc, warnings


def dump_yaml(doc: dict) -> str:
    header = (
        "# Generated by lms/tools/extract_tests.py; human review pending until someone replaces this line with 'Reviewed by <name>, <date>'.\n"
        "# This file is the source of truth:\n"
        "# edit it directly. Do NOT rename or reuse question keys (see AGENTS.md invariants).\n"
    )
    return header + yaml.safe_dump(doc, allow_unicode=True, sort_keys=False, width=100000)


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--repo-root", default=".", type=Path,
                    help="the kit folder (course.yaml source_root, i.e. kit/): --glob and the recorded source.file paths are relative to it")
    ap.add_argument("--glob", default="RecursosFormativos/*/Test_evaluacion/*.pdf")
    ap.add_argument("--out", required=True, type=Path, help="directory for M01.yaml … files")
    ap.add_argument("--report", type=Path, help="write a review report (markdown) here")
    ap.add_argument("--force", action="store_true", help="overwrite existing (possibly hand-reviewed) YAML")
    args = ap.parse_args(argv)

    root = args.repo_root.resolve()
    pdfs = sorted(root.glob(args.glob))
    if not pdfs:
        print(f"no PDFs match {args.glob!r} under {root}", file=sys.stderr)
        return 2
    args.out.mkdir(parents=True, exist_ok=True)

    report = ["# Test extraction review", ""]
    failed = False
    seen: dict[str, Path] = {}  # module key -> the PDF it came from
    for pdf in pdfs:
        try:
            doc, warnings = build_module(pdf, root)
            # The key comes from the file name (NN_*.pdf): two PDFs with the same number in different folders would write the
            # same Mnn.yaml, and with --force the second would silently replace the first.
            if doc["module"] in seen:
                raise ParseError(f"module key {doc['module']} already came from {seen[doc['module']].relative_to(root).as_posix()}: "
                                 f"narrow --glob or rename one file")
        except (ParseError, subprocess.CalledProcessError) as e:
            print(f"FAIL {pdf.name}: {e}", file=sys.stderr)
            report += [f"## {pdf.name}", f"- **FAILED**: {e}", ""]
            failed = True
            continue
        seen[doc["module"]] = pdf
        target = args.out / f"{doc['module']}.yaml"
        if target.exists() and not args.force:
            print(f"SKIP {target} exists (use --force to overwrite reviewed content)")
            report += [f"## {doc['module']} ({pdf.name})", "- skipped: YAML already exists", ""]
            continue
        target.write_text(dump_yaml(doc), encoding="utf-8", newline="\n")
        no_shuffle = [q["key"] for q in doc["questions"] if not q["shuffle"]]
        print(f"OK   {target} ({len(doc['questions'])} questions, {len(warnings)} warnings)")
        report += [f"## {doc['module']} ({pdf.name})"]
        report += [f"- WARNING {w}" for w in warnings] or ["- no warnings"]
        report += [f"- shuffle disabled (position-dependent options): {', '.join(no_shuffle) or 'none'}", ""]

    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text("\n".join(report) + "\n", encoding="utf-8", newline="\n")
        print(f"review report: {args.report}")
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
