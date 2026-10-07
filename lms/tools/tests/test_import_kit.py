"""Tests for lms/tools/import_kit.py with synthetic zips (the real 547 MB kit is only used by `make import-kit`)."""
import hashlib
import json
import struct
import sys
import zipfile
from pathlib import Path

import pytest
import yaml

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO / "lms" / "tools"))

import import_kit as ik  # noqa: E402

MOD = "RecursosFormativos/01_Información"
TEST_PDF = f"{MOD}/Test_evaluacion/01_Test_Información.pdf"
KIT_V1 = {
    f"{MOD}/01_Doc.pdf": b"%PDF doc v1",
    TEST_PDF: b"%PDF test v1",
    "Tripticos/t1.pdf": b"%PDF leaflet",
    "Posters_presentacion/p1.png": b"png-1",
    "Manual_implantacion.pdf": b"%PDF manual",
}
COURSE = {
    "id": "demo", "title": "Curso de prueba", "shortname_prefix": "DEMO", "category": "Pruebas", "source_root": "../../kit", "lang": "es",
    "completion": {"due_days": 30, "require_pdf_view": True},
    "quiz_defaults": {"pass_percent": 80, "attempts": 0, "grademethod": "highest", "shuffle_questions": True, "review": "after_close"},
    "certificate": {"template": "demo", "validity_months": 12}, "enrol": {"cohort": "empleados"},
    "welcome": {"text": "texts/bienvenida.md", "posters": "Posters_presentacion", "trypticos": "Tripticos"},
    "modules": [{"key": "M01", "title": "Módulo uno", "folder": MOD, "questions": "questions/M01.yaml"}],
}


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def write_tree(root: Path, files: dict[str, bytes]) -> None:
    for rel, data in files.items():
        (root / rel).parent.mkdir(parents=True, exist_ok=True)
        (root / rel).write_bytes(data)


def make_zip(path: Path, files: dict[str, bytes], top: str | None = "kit_concienciacion") -> Path:
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        for rel, data in files.items():
            z.writestr(f"{top}/{rel}" if top else rel, data)
    return path


def patch_cp437(path: Path) -> None:
    """Rewrite '@' in every entry name to 0xA2 (CP437/CP850 'ó') with the UTF-8 flag off: what INCIBE's zip looks like."""
    raw = path.read_bytes()
    out, i = bytearray(raw), 0
    while (i := raw.find(b"PK\x03\x04", i)) != -1:
        n = struct.unpack_from("<H", raw, i + 26)[0]
        out[i + 30:i + 30 + n] = raw[i + 30:i + 30 + n].replace(b"@", b"\xa2")
        i += 4
    i = 0
    while (i := raw.find(b"PK\x01\x02", i)) != -1:
        n = struct.unpack_from("<H", raw, i + 28)[0]
        out[i + 46:i + 46 + n] = raw[i + 46:i + 46 + n].replace(b"@", b"\xa2")
        i += 4
    path.write_bytes(bytes(out))


@pytest.fixture
def repo(tmp_path):
    r = tmp_path / "repo"
    (r / "courses/demo/texts").mkdir(parents=True)
    (r / "courses/demo/texts/bienvenida.md").write_text("Hola\n", encoding="utf-8")
    (r / "courses/demo/course.yaml").write_text(yaml.safe_dump(COURSE, allow_unicode=True, sort_keys=False), encoding="utf-8")
    bank = {"module": "M01", "source": {"file": TEST_PDF, "sha256": sha(KIT_V1[TEST_PDF])},
            "questions": [{"key": "M01-Q01", "text": "¿Uno?", "options": {"a": "A", "b": "B", "c": "C", "d": "D"}, "answer": "a"}]}
    (r / "courses/demo/questions").mkdir()
    (r / "courses/demo/questions/M01.yaml").write_text(yaml.safe_dump(bank, allow_unicode=True, sort_keys=False), encoding="utf-8")
    (r / "upload").mkdir()
    return r


def run(repo: Path, *extra: str) -> int:
    return ik.main(["--repo-root", str(repo), "--course", "demo", *extra])


def report(repo: Path) -> str:
    return (repo / "upload/import-report.md").read_text(encoding="utf-8")


# ------------------------------------------------------------------ happy paths
def test_first_import_creates_kit_strips_top_folder_and_skips_tooling(repo):
    files = KIT_V1 | {"Ataques_dirigidos/Password.txt": b"x", "Manual_Gophish/g.pdf": b"g", f"{MOD}/Thumbs.db": b"t"}
    make_zip(repo / "upload/kit.zip", files)
    assert run(repo) == 3  # dry run: changes found
    assert not (repo / "kit").exists()  # ... and nothing written
    assert run(repo, "--apply") == 0
    kit = repo / "kit"
    assert sorted(p.relative_to(kit).as_posix() for p in kit.rglob("*") if p.is_file()) == sorted([*KIT_V1, "IMPORT.json"])
    meta = json.loads((kit / "IMPORT.json").read_text(encoding="utf-8"))
    assert meta["zip"] == "kit.zip" and meta["files"][TEST_PDF] == sha(KIT_V1[TEST_PDF])
    assert "Ataques_dirigidos ×1" in report(repo) and "Thumbs.db ×1" in report(repo)
    assert not (repo / "upload/.staging").exists()


def test_cp437_names_are_decoded_to_real_accents(repo):
    zp = make_zip(repo / "upload/kit.zip", {k.replace("ó", "@"): v for k, v in KIT_V1.items()})
    patch_cp437(zp)
    assert run(repo, "--apply") == 0
    assert (repo / "kit" / MOD / "01_Doc.pdf").read_bytes() == KIT_V1[f"{MOD}/01_Doc.pdf"]


def test_identical_kit_reports_nothing_and_second_apply_does_not_swap(repo):
    make_zip(repo / "upload/kit.zip", KIT_V1)
    assert run(repo, "--apply") == 0
    assert run(repo) == 0
    assert "Nothing differs" in report(repo)
    assert run(repo, "--apply") == 0  # same zip already recorded in IMPORT.json: nothing to swap
    assert not (repo / "upload/.previous-kit").exists()


def test_update_classifies_changes_and_flags_test_pdf(repo):
    make_zip(repo / "upload/kit.zip", KIT_V1)
    assert run(repo, "--apply") == 0
    v2 = dict(KIT_V1)
    v2[f"{MOD}/01_Doc.pdf"] = b"%PDF doc v2"
    v2[TEST_PDF] = b"%PDF test v2"
    del v2["Posters_presentacion/p1.png"]
    v2["Posters_presentacion/p2.png"] = b"png-2"
    v2["Manual_implantacion.pdf"] = b"%PDF manual v2"
    (repo / "upload/kit.zip").unlink()
    make_zip(repo / "upload/kit2.zip", v2)
    assert run(repo) == 3
    text = report(repo)
    assert "added 1, removed 1, changed 3" in text
    assert "**M01**" in text and "**Welcome: posters**" in text and "**Kit content the course does not use**" in text
    assert "M01: test PDF changed" in text and "make extract FORCE=1" in text
    assert (repo / "kit" / TEST_PDF).read_bytes() == KIT_V1[TEST_PDF]  # dry run left the kit alone
    assert run(repo, "--apply") == 0
    assert (repo / "kit" / TEST_PDF).read_bytes() == b"%PDF test v2"
    assert (repo / "upload/.previous-kit" / TEST_PDF).read_bytes() == KIT_V1[TEST_PDF]  # one previous kit kept for rollback


# ------------------------------------------------------------------ problems
def test_missing_module_pdf_blocks_apply_unless_forced(repo):
    make_zip(repo / "upload/kit.zip", KIT_V1)
    assert run(repo, "--apply") == 0
    broken = {k: v for k, v in KIT_V1.items() if k != f"{MOD}/01_Doc.pdf"}
    (repo / "upload/kit.zip").unlink()
    make_zip(repo / "upload/kit2.zip", broken)
    assert run(repo) == 1
    assert "Problems" in report(repo) and "M01" in report(repo)
    assert run(repo, "--apply") == 1
    assert (repo / "kit" / MOD / "01_Doc.pdf").exists()  # refused: kit untouched
    assert not (repo / "upload/.staging").exists()
    assert run(repo, "--apply", "--force") == 0
    assert not (repo / "kit" / MOD / "01_Doc.pdf").exists()


def test_renamed_test_pdf_is_a_problem(repo):
    files = {k: v for k, v in KIT_V1.items() if k != TEST_PDF} | {f"{MOD}/Test_evaluacion/01_Test_NUEVO.pdf": b"%PDF"}
    make_zip(repo / "upload/kit.zip", files)
    assert run(repo) == 1
    assert "is not in the zip" in report(repo)


def test_new_module_folder_is_reported(repo):
    make_zip(repo / "upload/kit.zip", KIT_V1 | {"RecursosFormativos/10_Nuevo/10_Nuevo.pdf": b"%PDF"})
    run(repo)
    assert "10_Nuevo" in report(repo) and "add ONE entry" in report(repo)


# ------------------------------------------------------------------ unsafe zips (exit 2, nothing written)
@pytest.mark.parametrize("name", ["../evil.txt", "kit_concienciacion/../../evil.txt", "/abs/evil.txt", "C:/evil.txt", "a\\..\\evil.txt"])
def test_path_traversal_and_absolute_paths_are_rejected(repo, name):
    make_zip(repo / "upload/kit.zip", KIT_V1 | {name: b"x"}, top=None)
    assert run(repo, "--apply") == 2
    assert not (repo / "kit").exists() and not (repo / "evil.txt").exists()


def test_symlink_is_rejected(repo):
    with zipfile.ZipFile(repo / "upload/kit.zip", "w") as z:
        z.writestr("kit_concienciacion/a.pdf", b"%PDF")
        link = zipfile.ZipInfo("kit_concienciacion/link.pdf")
        link.external_attr = (0o120777 << 16)
        z.writestr(link, "/etc/passwd")
    assert run(repo, "--apply") == 2
    assert not (repo / "kit").exists()


def test_case_or_normalisation_duplicates_are_rejected(repo):
    with zipfile.ZipFile(repo / "upload/kit.zip", "w") as z:
        z.writestr("k/Tripticos/A.pdf", b"1")
        z.writestr("k/tripticos/a.pdf", b"2")
    assert run(repo) == 2


def test_zip_bomb_is_rejected(repo):
    with zipfile.ZipFile(repo / "upload/kit.zip", "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("k/big.pdf", b"\0" * (12 * 1024 * 1024))
    assert run(repo) == 2


@pytest.mark.parametrize("zips", [[], ["a.zip", "b.zip"]])
def test_needs_exactly_one_zip(repo, zips):
    for n in zips:
        make_zip(repo / "upload" / n, KIT_V1)
    assert run(repo) == 2


def test_zip_option_must_live_in_upload(repo, tmp_path):
    outside = make_zip(tmp_path / "elsewhere.zip", KIT_V1)
    assert run(repo, "--zip", str(outside)) == 2
    make_zip(repo / "upload/a.zip", KIT_V1)
    make_zip(repo / "upload/b.zip", KIT_V1 | {"Tripticos/t2.pdf": b"n"})
    assert run(repo, "--zip", str(repo / "upload/b.zip")) == 3


def test_not_a_zip_is_a_usage_error(repo):
    (repo / "upload/kit.zip").write_bytes(b"this is not a zip")
    assert run(repo) == 2


def test_source_root_must_not_be_the_repo_or_upload(repo):
    course = dict(COURSE, source_root="../..")
    (repo / "courses/demo/course.yaml").write_text(yaml.safe_dump(course, allow_unicode=True, sort_keys=False), encoding="utf-8")
    make_zip(repo / "upload/kit.zip", KIT_V1)
    assert run(repo, "--apply") == 2


def test_apply_refuses_a_folder_that_is_not_a_kit(repo):
    """source_root mistyped as a folder with other content: --apply would move it away and put the zip in its place."""
    course = dict(COURSE, source_root="../../lms")
    (repo / "courses/demo/course.yaml").write_text(yaml.safe_dump(course, allow_unicode=True, sort_keys=False), encoding="utf-8")
    write_tree(repo / "lms", {"tools/build.py": b"precious"})
    make_zip(repo / "upload/kit.zip", KIT_V1)
    assert run(repo) in (1, 3)  # a dry run only reports
    assert run(repo, "--apply") == 2
    assert (repo / "lms/tools/build.py").read_bytes() == b"precious" and not (repo / "upload/.previous-kit").exists()


def test_a_hand_copied_kit_can_still_be_adopted(repo):
    """First adoption: kit/ was copied by hand (no IMPORT.json) but holds the course's module folder, so it is a kit."""
    write_tree(repo / "kit", KIT_V1)
    make_zip(repo / "upload/kit.zip", KIT_V1)
    assert run(repo, "--apply") == 0
    assert (repo / "kit/IMPORT.json").is_file()


# ------------------------------------------------------------------ the tooling never lands in kit/, wherever the zip puts it
def test_tooling_is_excluded_at_any_depth(repo):
    """An extra file next to the top folder means the top folder is not stripped: the tooling then sits one level down."""
    files = {f"kit_concienciacion/{k}": v for k, v in KIT_V1.items()} | {
        "LEEME.txt": b"new in this release",
        "kit_concienciacion/Ataques_dirigidos/Password.txt": b"secret",
        "kit_concienciacion/Manual_Gophish/manual.pdf": b"g",
        "kit_concienciacion/RecursosFormativos/__MACOSX/._x": b"m",
    }
    make_zip(repo / "upload/kit.zip", files, top=None)
    assert run(repo) == 1  # the course's folders are not where course.yaml expects them: a problem, reported
    assert run(repo, "--apply", "--force") == 0
    landed = [p.relative_to(repo / "kit").as_posix() for p in (repo / "kit").rglob("*") if p.is_file()]
    assert "kit_concienciacion/Manual_implantacion.pdf" in landed
    assert not [p for p in landed if "Ataques_dirigidos" in p or "Manual_Gophish" in p or "__MACOSX" in p], landed
    assert "Ataques_dirigidos ×1" in report(repo) and "Manual_Gophish ×1" in report(repo)


# ------------------------------------------------------------------ the swap never leaves the repo without a kit
def fail_rename_of(monkeypatch, name: str):
    real = Path.rename

    def rename(self, target):
        if self.name == name:
            raise PermissionError(13, "held open by another program", str(self))
        return real(self, target)

    monkeypatch.setattr(Path, "rename", rename)


def test_failed_swap_puts_the_current_kit_back_and_keeps_the_previous_one(repo, monkeypatch, capsys):
    make_zip(repo / "upload/kit.zip", KIT_V1)
    assert run(repo, "--apply") == 0
    v2 = KIT_V1 | {"Tripticos/t2.pdf": b"%PDF two"}
    (repo / "upload/kit.zip").unlink()
    make_zip(repo / "upload/kit.zip", v2)
    assert run(repo, "--apply") == 0  # kit = v2, .previous-kit = v1
    (repo / "upload/kit.zip").unlink()
    make_zip(repo / "upload/kit.zip", v2 | {"Tripticos/t3.pdf": b"%PDF three"})

    fail_rename_of(monkeypatch, ".staging")  # the new kit cannot be moved into place
    assert run(repo, "--apply") == 4
    assert "unexpected PermissionError" in capsys.readouterr().err
    monkeypatch.undo()
    assert (repo / "kit/Tripticos/t2.pdf").exists() and not (repo / "kit/Tripticos/t3.pdf").exists(), "the current kit is back"
    assert not (repo / "upload/.previous-kit/Tripticos/t2.pdf").exists(), "the only way back (v1) was not thrown away"
    assert (repo / "upload/.previous-kit" / TEST_PDF).exists()
    assert not (repo / "upload/.outgoing-kit").exists() and not (repo / "upload/.staging").exists()
    assert run(repo, "--apply") == 0 and (repo / "kit/Tripticos/t3.pdf").exists()  # and the retry works


def test_swap_interrupted_by_a_crash_is_recovered_on_the_next_run(repo, capsys):
    make_zip(repo / "upload/kit.zip", KIT_V1)
    assert run(repo, "--apply") == 0
    # crash right after the current kit was moved aside: no kit/ at all
    (repo / "kit").rename(repo / "upload/.outgoing-kit")
    assert run(repo) == 0 and "previous kit was put back" in capsys.readouterr().err
    assert (repo / "kit" / TEST_PDF).exists() and not (repo / "upload/.outgoing-kit").exists()
    # crash after the new kit arrived, before its predecessor was filed as the previous kit
    write_tree(repo / "upload/.outgoing-kit", {"old.txt": b"older kit"})
    assert run(repo) == 0
    assert (repo / "upload/.previous-kit/old.txt").exists() and not (repo / "upload/.outgoing-kit").exists()


def test_corrupt_import_record_is_an_error_not_a_traceback(repo, capsys):
    make_zip(repo / "upload/kit.zip", KIT_V1)
    assert run(repo, "--apply") == 0
    (repo / "kit/IMPORT.json").write_text("{not json", encoding="utf-8")
    assert run(repo) == 4
    assert "unexpected JSONDecodeError" in capsys.readouterr().err and (repo / "kit" / TEST_PDF).exists()


def test_real_course_reads_the_kit_folder():
    """The committed course must point at kit/ (the folder import-kit maintains)."""
    course = yaml.safe_load((REPO / "courses/concienciacion/course.yaml").read_text(encoding="utf-8"))
    assert (REPO / "courses/concienciacion" / course["source_root"]).resolve() == (REPO / "kit").resolve()
