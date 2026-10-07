"""Tests for lms/tools/extract_tests.py against the INCIBE test PDFs the operator imported into kit/ (`make setup`).

kit/ is not part of the repository (INCIBE content): the tests that need it are skipped on a fresh clone."""
import sys
from pathlib import Path

import pytest
import yaml

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO / "lms" / "tools"))

import extract_tests as ex  # noqa: E402

KIT = REPO / "kit"  # course.yaml source_root; question `source.file` paths are relative to it
PDFS = sorted(KIT.glob("RecursosFormativos/*/Test_evaluacion/*.pdf"))
QUESTIONS_DIR = REPO / "courses" / "concienciacion" / "questions"

needs_kit = pytest.mark.skipif(not PDFS, reason="kit/ not imported: download INCIBE's zip into upload/ and run `make setup`")

# Quirks genuinely in INCIBE's source. M04-Q10's stem is kept verbatim (only warned); M07 has one repaired split word.
KNOWN_WARNINGS = {
    "M04": ["Q10: stem does not end in punctuation"],
    "M07": ["joined split word"],
}


def test_committed_banks_never_shuffle_position_dependent_options():
    """Scans the reviewed YAML itself (not hand-picked strings): «c», «todas las anteriores»… must keep their order."""
    bad = []
    for path in sorted(REPO.glob("courses/*/questions/M*.yaml")):
        for q in yaml.safe_load(path.read_text(encoding="utf-8"))["questions"]:
            if q.get("shuffle", True) and any(ex.NO_SHUFFLE_RE.search(o) for o in q["options"].values()):
                bad.append(f"{path.stem}/{q['key']}")
    assert bad == []


@needs_kit
def test_all_nine_pdfs_present():
    assert len(PDFS) == 9


@pytest.fixture(scope="module")
def built():
    if not PDFS:
        pytest.skip("kit/ not imported: run `make setup`")
    return {ex.module_key(p): ex.build_module(p, KIT) for p in PDFS}


@pytest.mark.parametrize("key", [f"M{n:02d}" for n in range(1, 10)])
def test_structure(built, key):
    doc, warnings = built[key]
    qs = doc["questions"]
    assert [q["key"] for q in qs] == [f"{key}-Q{i:02d}" for i in range(1, 11)]
    for q in qs:
        assert list(q["options"]) == list("abcd")
        assert q["answer"] in "abcd"
        assert all(q["options"].values()) and q["text"]
    expected = KNOWN_WARNINGS.get(key, [])
    assert len(warnings) == len(expected)
    for got, want in zip(warnings, expected, strict=True):
        assert got.startswith(want)


@pytest.mark.parametrize("key", [f"M{n:02d}" for n in range(1, 10)])
def test_answers_are_one_letter_a_to_d(built, key):
    doc, _ = built[key]
    assert len(doc["questions"]) == 10
    assert all(q["answer"] in "abcd" and q["answer"] in q["options"] for q in doc["questions"])


def test_split_word_is_repaired_in_m07(built):
    doc, warnings = built["M07"]
    q10 = doc["questions"][9]
    assert "sitios de descargas, juegos" in q10["options"]["a"]
    assert warnings and "split word" in warnings[0]


def test_blank_line_inside_option_is_continuation(built):
    doc, _ = built["M09"]
    assert doc["questions"][6]["options"]["c"].endswith("la extensión del archivo sea .exe.")


def test_no_running_headers_leak_into_text(built):
    for doc, _ in built.values():
        for q in doc["questions"]:
            blob = " ".join([q["text"], *q["options"].values()])
            assert "SOLUCIONES" not in blob and "INSTITUTO NACIONAL" not in blob


@pytest.mark.parametrize(
    "option,expected_shuffle",
    [
        ("Todas las respuestas son ciertas.", False),
        ("Ninguna de las anteriores es cierta.", False),
        ("La «a» y la «b».", False),
        ("Contratos que sentarán las bases entre ambas partes.", True),
        ("En caso de incidente no hay ninguna obligación de comunicarlo.", True),
        ("Cifrada.", True),
    ],
)
def test_no_shuffle_rule(option, expected_shuffle):
    assert (not ex.NO_SHUFFLE_RE.search(option)) is expected_shuffle


def test_join_repairs_only_lone_consonants():
    notes: list[str] = []
    assert ex._join(["sitios de d\t\t", "escargas y más"], notes) == "sitios de descargas y más"
    assert len(notes) == 1
    # real one-letter words must keep their space
    assert ex._join(["Es cuestión de pagar o", "no pagar"], []) == "Es cuestión de pagar o no pagar"
    assert ex._join(["Se usa y", "se tira"], []) == "Se usa y se tira"


def test_parse_error_without_solutions():
    with pytest.raises(ex.ParseError):
        ex.parse_test_text("TEST\nSelecciona para cada pregunta la respuesta correcta.\n   1. Hola\n")


def test_validate_rejects_wrong_question_count():
    with pytest.raises(ex.ParseError):
        ex.validate([], expected=10)


@needs_kit
def test_two_pdfs_with_the_same_module_number_are_refused(tmp_path):
    """The key comes from the file name: with --force the second PDF would silently replace the first one's YAML."""
    for folder in ("a", "b"):
        (tmp_path / "kit" / folder).mkdir(parents=True)
        (tmp_path / "kit" / folder / f"01_Test_{folder}.pdf").write_bytes(PDFS[0].read_bytes())
    out = tmp_path / "questions"
    rc = ex.main(["--repo-root", str(tmp_path / "kit"), "--glob", "*/*.pdf", "--out", str(out), "--force", "--report", str(tmp_path / "r.md")])
    assert rc == 1
    assert yaml.safe_load((out / "M01.yaml").read_text(encoding="utf-8"))["source"]["file"] == "a/01_Test_a.pdf", "the first one stays"
    assert "already came from a/01_Test_a.pdf" in (tmp_path / "r.md").read_text(encoding="utf-8")


@needs_kit
def test_committed_yaml_matches_current_pdfs():
    """Drift guard: fails if an INCIBE PDF is replaced without re-running `make extract` and reviewing the diff."""
    files = sorted(QUESTIONS_DIR.glob("M*.yaml"))
    assert len(files) == 9, "run `make extract` first"
    all_keys = []
    for f in files:
        doc = yaml.safe_load(f.read_text(encoding="utf-8"))
        src = KIT / doc["source"]["file"]
        assert src.exists(), f"{f.name}: source PDF missing"
        assert ex.sha256(src) == doc["source"]["sha256"], (
            f"{f.name}: source PDF changed since extraction; re-extract and review the git diff"
        )
        assert len(doc["questions"]) == 10
        all_keys += [q["key"] for q in doc["questions"]]
    assert len(all_keys) == len(set(all_keys)) == 90
