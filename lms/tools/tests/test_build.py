"""Tests for lms/tools/build.py: a synthetic mini-kit for behaviour, the real kit for determinism and validity."""
import copy
import json
import re
import shutil
import sys
from pathlib import Path

import pytest
import yaml
from PIL import Image

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO / "lms" / "tools"))

import build as b  # noqa: E402

COURSE = {
    "id": "demo", "title": "Curso de prueba", "shortname_prefix": "DEMO", "category": "Pruebas", "source_root": "../..", "lang": "es",
    "credit": "Fuente: INCIBE",
    "completion": {"due_days": 30, "require_pdf_view": True},
    "quiz_defaults": {"pass_percent": 80, "attempts": 0, "grademethod": "highest", "shuffle_questions": True, "review": "after_close"},
    "certificate": {"template": "demo", "validity_months": 12}, "enrol": {"cohort": "empleados"},
    "images": {"max_px": 1600, "jpeg_quality": 85},
    "welcome": {"text": "texts/bienvenida.md", "posters": "Kit/Carteles", "trypticos": "Kit/Tripticos"},
    "trainer_material": True,
    "modules": [{"key": "M01", "title": "Módulo uno", "folder": "Kit/M01", "questions": "questions/M01.yaml"}],
}
QUESTIONS = {
    "module": "M01",
    "questions": [
        {"key": "M01-Q01", "text": "¿Pregunta uno?", "options": {"a": "A", "b": "B", "c": "C", "d": "D"}, "answer": "a", "shuffle": True},
        {"key": "M01-Q02", "text": "¿Pregunta dos?", "options": {"a": "A", "b": "B", "c": "C", "d": "Todas las anteriores"}, "answer": "d", "shuffle": False},
    ],
}


def write_yaml(path: Path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(yaml.safe_dump(data, allow_unicode=True, sort_keys=False), encoding="utf-8")


def make_png(path: Path, size, mode="RGB"):
    path.parent.mkdir(parents=True, exist_ok=True)
    Image.new(mode, size, (200, 30, 30, 255) if mode == "RGBA" else (200, 30, 30)).save(path)


@pytest.fixture
def repo(tmp_path):
    r = tmp_path / "repo"
    write_yaml(r / "courses/demo/course.yaml", COURSE)
    write_yaml(r / "courses/demo/questions/M01.yaml", QUESTIONS)
    (r / "courses/demo/texts").mkdir(parents=True)
    (r / "courses/demo/texts/bienvenida.md").write_text("## Hola {{course_title}} {{cycle}}\n\nNota {{pass_percent}} %, hasta {{due_date}}.\n", encoding="utf-8")
    (r / "Kit/M01").mkdir(parents=True)
    (r / "Kit/M01/01_Doc.pdf").write_bytes(b"%PDF-1.4 fake document")
    make_png(r / "Kit/M01/Consejos/c1.png", (100, 100), "RGBA")
    make_png(r / "Kit/M01/Posters/p1.png", (2400, 3000))
    (r / "Kit/M01/Presentacion").mkdir()
    (r / "Kit/M01/Presentacion/deck.pptx").write_bytes(b"PK fake deck")
    make_png(r / "Kit/Carteles/inicio.png", (1200, 1600))
    (r / "Kit/Tripticos").mkdir()
    (r / "Kit/Tripticos/t1.pdf").write_bytes(b"%PDF-1.4 leaflet")
    return r


def do_build(repo: Path, out: Path, start="2026-10-01", cycle=2026):
    manifest, warnings, assets = b.build(repo, "demo", cycle, start, out)
    b.write_manifest(manifest, out / f"demo-{cycle}")
    return manifest, warnings


def item(manifest, suffix):
    return next(i for s in manifest["sections"] for i in s["items"] if i["key"].endswith(suffix))


def tree(root: Path) -> dict:
    return {p.relative_to(root).as_posix(): p.read_bytes() for p in sorted(root.rglob("*")) if p.is_file()}


# ------------------------------------------------------------------ structure
def test_mini_kit_builds_and_matches_schema(repo, tmp_path):
    m, warnings = do_build(repo, tmp_path / "out")
    assert warnings == []
    assert [s["key"] for s in m["sections"]] == [
        "demo:2026:S00", "demo:2026:M01", "demo:2026:TRAINER", "demo:2026:CERT"]
    assert m["course"]["shortname"] == "DEMO-2026" and m["course"]["idnumber"] == "demo:2026"
    assert m["course"]["due_date"] == "2026-10-31"
    quiz = item(m, ":M01:quiz")
    assert quiz["requires"] == ["demo:2026:M01:pdf"] and len(quiz["questions"]) == 2
    assert quiz["questions"][1]["shuffle"] is False
    assert m["completion"]["required_item_keys"] == ["demo:2026:M01:quiz"]
    assert item(m, ":CERT:certificate")["requires"] == ["demo:2026:M01:quiz"]
    trainer = next(s for s in m["sections"] if s["key"].endswith("TRAINER"))
    assert trainer["visible"] is False and len(trainer["items"]) == 1


def test_template_variables_and_spanish_date(repo, tmp_path):
    m, _ = do_build(repo, tmp_path / "out")
    html = m["sections"][0]["summary_html"]
    assert "Curso de prueba 2026" in html and "80 %" in html and "31 de octubre de 2026" in html


def test_images_are_optimised_and_small_ones_copied(repo, tmp_path):
    out = tmp_path / "out"
    m, _ = do_build(repo, out)
    poster = item(m, ":M01:posters")["files"][0]
    assert poster["transform"].startswith("jpeg:1600:q85") and poster["filename"] == "p1.jpg"
    with Image.open(out / "demo-2026" / poster["path"]) as im:
        assert max(im.size) == 1600
    tip = item(m, ":M01:consejos")["files"][0]
    assert tip["transform"] == "copy"
    assert (out / "demo-2026" / tip["path"]).read_bytes() == (repo / "Kit/M01/Consejos/c1.png").read_bytes()
    assert "@@PLUGINFILE@@/p1.jpg" in item(m, ":M01:posters")["content_html"]


def test_optional_parts_are_skipped_when_absent(repo, tmp_path):
    for p in [repo / "Kit/M01/Consejos/c1.png", repo / "Kit/M01/Posters/p1.png"]:
        p.unlink()
    m, _ = do_build(repo, tmp_path / "out")
    assert [i["key"].split(":")[-1] for i in next(s for s in m["sections"] if s["key"].endswith(":M01"))["items"]] == ["pdf", "quiz"]


def test_retired_questions_leave_quiz_but_are_listed(repo, tmp_path):
    q = copy.deepcopy(QUESTIONS)
    q["questions"][0]["retired"] = True
    write_yaml(repo / "courses/demo/questions/M01.yaml", q)
    m, _ = do_build(repo, tmp_path / "out")
    assert [x["key"] for x in item(m, ":M01:quiz")["questions"]] == ["M01-Q02"]
    assert m["retired_question_keys"] == ["M01-Q01"]


# ------------------------------------------------------------------ determinism and versioning
def test_two_builds_are_byte_identical(repo, tmp_path):
    do_build(repo, tmp_path / "a")
    do_build(repo, tmp_path / "b")
    ta, tb = tree(tmp_path / "a" / "demo-2026"), tree(tmp_path / "b" / "demo-2026")
    assert ta.keys() == tb.keys() and ta == tb


def test_content_version_ignores_dates_but_tracks_content(repo, tmp_path):
    m1, _ = do_build(repo, tmp_path / "o1", start="2026-10-01")
    m2, _ = do_build(repo, tmp_path / "o2", start="2026-11-15")
    assert m1["content_version"] == m2["content_version"]
    assert m1["course"]["due_date"] != m2["course"]["due_date"]

    q = copy.deepcopy(QUESTIONS)
    q["questions"][0]["text"] = "¿Pregunta uno, corregida?"
    write_yaml(repo / "courses/demo/questions/M01.yaml", q)
    m3, _ = do_build(repo, tmp_path / "o3")
    assert m3["content_version"] != m1["content_version"]
    assert item(m3, ":M01:quiz")["content_hash"] != item(m1, ":M01:quiz")["content_hash"]
    # untouched items keep their hash, so the sync leaves them alone
    assert item(m3, ":M01:pdf")["content_hash"] == item(m1, ":M01:pdf")["content_hash"]
    assert item(m3, ":M01:posters")["content_hash"] == item(m1, ":M01:posters")["content_hash"]


def test_replacing_a_pdf_changes_only_that_item(repo, tmp_path):
    m1, _ = do_build(repo, tmp_path / "o1")
    (repo / "Kit/M01/01_Doc.pdf").write_bytes(b"%PDF-1.4 new edition")
    m2, _ = do_build(repo, tmp_path / "o2")
    assert item(m2, ":M01:pdf")["content_hash"] != item(m1, ":M01:pdf")["content_hash"]
    assert item(m2, ":M01:quiz")["content_hash"] == item(m1, ":M01:quiz")["content_hash"]


# ------------------------------------------------------------------ monthly per-module deadlines
def monthly_repo(repo: Path, modules=("M01", "M02", "M03"), **extra):
    """Turn the mini course into a monthly one with several modules (copies of M01)."""
    c = copy.deepcopy(COURSE)
    c["completion"] = {"schedule": {"mode": "monthly"}, "require_pdf_view": True}
    c["modules"] = []
    for k in modules:
        if k != "M01" and not (repo / f"Kit/{k}").exists():
            shutil.copytree(repo / "Kit/M01", repo / f"Kit/{k}")
        q = copy.deepcopy(QUESTIONS)
        q["module"] = k
        for x in q["questions"]:
            x["key"] = x["key"].replace("M01", k)
        write_yaml(repo / f"courses/demo/questions/{k}.yaml", q)
        c["modules"].append({"key": k, "title": f"Módulo {k}", "folder": f"Kit/{k}", "questions": f"questions/{k}.yaml", **extra.get(k, {})})
    write_yaml(repo / "courses/demo/course.yaml", c)


def section(manifest, module):
    return next(s for s in manifest["sections"] if s["key"].endswith(f":{module}"))


@pytest.mark.parametrize(
    "start,n,expected",
    [
        ("2026-10-01", 1, "2026-10-31"), ("2026-10-15", 1, "2026-10-31"), ("2026-10-31", 1, "2026-10-31"),
        ("2026-10-01", 2, "2026-11-30"), ("2026-10-01", 3, "2026-12-31"), ("2026-10-01", 4, "2027-01-31"),
        ("2026-10-01", 5, "2027-02-28"), ("2027-10-01", 5, "2028-02-29"),  # non-leap and leap February
        ("2026-10-01", 9, "2027-06-30"), ("2026-01-01", 12, "2026-12-31"), ("2026-12-15", 1, "2026-12-31"),
    ],
)
def test_month_end_is_the_last_day_of_the_calendar_month(start, n, expected):
    assert b.month_end(b.dt.date.fromisoformat(start), n).isoformat() == expected


def test_monthly_module_deadlines_and_course_end(repo, tmp_path):
    monthly_repo(repo)
    m, _ = do_build(repo, tmp_path / "out")
    assert [section(m, k)["due_date"] for k in ("M01", "M02", "M03")] == ["2026-10-31", "2026-11-30", "2026-12-31"]
    assert m["course"]["due_date"] == "2026-12-31", "the course ends with its last module"
    assert m["completion"]["schedule"] == "monthly" and "due_days" not in m["completion"]
    assert all("due_date" not in s for s in m["sections"] if not s["key"].split(":")[-1].startswith("M"))
    # Dates are only INITIAL defaults (the administrator owns them in Moodle): the visible text points at the Timeline instead.
    for k in ("M01", "M02"):
        assert b.DEADLINE_NOTE in section(m, k)["summary_html"] and "2026" not in section(m, k)["summary_html"]
    # the certificate still needs every quiz, whatever the deadlines
    assert item(m, ":CERT:certificate")["requires"] == [f"demo:2026:{k}:quiz" for k in ("M01", "M02", "M03")]


def test_due_month_overrides_the_position(repo, tmp_path):
    monthly_repo(repo, M02={"due_month": 5})
    m, _ = do_build(repo, tmp_path / "out")
    assert section(m, "M02")["due_date"] == "2027-02-28" and section(m, "M03")["due_date"] == "2026-12-31"
    assert m["course"]["due_date"] == "2027-02-28"


def test_deadlines_never_enter_content_hash_or_version(repo, tmp_path):
    monthly_repo(repo)
    m1, _ = do_build(repo, tmp_path / "o1", start="2026-10-01")
    m2, _ = do_build(repo, tmp_path / "o2", start="2027-03-10")
    assert section(m1, "M01")["due_date"] != section(m2, "M01")["due_date"]
    assert m1["content_version"] == m2["content_version"]
    for k in ("pdf", "quiz"):
        assert item(m1, f":M02:{k}")["content_hash"] == item(m2, f":M02:{k}")["content_hash"]
    # moving one module's deadline changes no content version either
    monthly_repo(repo, M01={"due_month": 4})
    m3, _ = do_build(repo, tmp_path / "o3", start="2026-10-01")
    assert m3["content_version"] == m1["content_version"] and section(m3, "M01")["due_date"] == "2027-01-31"


def test_review_policy_is_only_an_initial_default(repo, tmp_path):
    """The manifest carries the review policy for new quizzes, but it never makes an existing quiz "changed": the
    administrator owns Review options in Moodle, so a deploy must not re-save (or revert) them."""
    m1, _ = do_build(repo, tmp_path / "o1")
    edit_course(repo, lambda c: c["quiz_defaults"].update(review="immediately"))
    m2, _ = do_build(repo, tmp_path / "o2")
    assert item(m2, ":M01:quiz")["review"] == "immediately" != item(m1, ":M01:quiz")["review"]
    assert item(m2, ":M01:quiz")["content_hash"] == item(m1, ":M01:quiz")["content_hash"]
    assert m2["content_version"] == m1["content_version"]


def test_no_date_is_printed_in_learner_texts(repo, tmp_path):
    """Module summaries must survive an administrator moving any deadline: no rendered date in them. (The welcome text may
    still use {{start_date}}/{{due_date}} in other courses; the real course's text does not, see the next test.)"""
    monthly_repo(repo)
    m1, _ = do_build(repo, tmp_path / "o1", start="2026-10-01")
    m2, _ = do_build(repo, tmp_path / "o2", start="2027-03-10")
    modules = lambda m: [s["summary_html"] for s in m["sections"] if re.search(r":M\d{2}$", s["key"])]
    assert modules(m1) == modules(m2) and modules(m1)


def test_real_welcome_text_prints_no_date():
    text = (Path(__file__).resolve().parents[3] / "courses/concienciacion/texts/bienvenida.md").read_text(encoding="utf-8")
    assert "{{start_date}}" not in text and "{{due_date}}" not in text


def test_legacy_single_deadline_still_builds(repo, tmp_path):
    m, _ = do_build(repo, tmp_path / "out")
    assert m["completion"]["due_days"] == 30 and "schedule" not in m["completion"]
    assert all("due_date" not in s for s in m["sections"])


# ------------------------------------------------------------------ failures must be loud and specific
def edit_course(repo: Path, fn):
    c = copy.deepcopy(COURSE)
    fn(c)
    write_yaml(repo / "courses/demo/course.yaml", c)


@pytest.mark.parametrize(
    "mutate,message",
    [
        (lambda c: c.update(unexpected=1), "unexpected"),
        (lambda c: c["modules"][0].update(key="X1"), "does not match"),
        (lambda c: c["modules"].append(copy.deepcopy(c["modules"][0])), "duplicate module keys"),
        (lambda c: c["modules"][0].update(folder="Kit/Nope"), "module folder not found"),
        (lambda c: c["modules"][0].update(folder="../../../etc"), "escapes source_root"),
        (lambda c: c["quiz_defaults"].pop("review"), "quiz settings missing"),
        (lambda c: c.update(id="other"), "id is 'other'"),
        (lambda c: c["quiz_defaults"].update(review="bogus"), "is not one of"),
        (lambda c: c["completion"].update(schedule={"mode": "monthly"}), "is valid under each of"),  # both due_days and schedule
        (lambda c: c["completion"].pop("due_days"), "is not valid under any of the given schemas"),  # neither
        (lambda c: c["completion"].update(schedule={"mode": "weekly"}), "is not valid under any|is not one of|is valid under"),
        (lambda c: c["modules"][0].update(due_month=2), "due_month needs completion.schedule"),
    ],
)
def test_bad_course_yaml_is_rejected(repo, tmp_path, mutate, message):
    edit_course(repo, mutate)
    with pytest.raises(b.BuildError, match=message):
        do_build(repo, tmp_path / "out")


def test_two_pdfs_in_a_module_need_an_override(repo, tmp_path):
    (repo / "Kit/M01/extra.pdf").write_bytes(b"%PDF-1.4 second")
    with pytest.raises(b.BuildError, match="2 candidates"):
        do_build(repo, tmp_path / "out")
    edit_course(repo, lambda c: c["modules"][0].update(files={"pdf": "01_Doc.pdf"}))
    m, _ = do_build(repo, tmp_path / "out")
    assert item(m, ":M01:pdf")["file"]["filename"] == "01_Doc.pdf"


def test_missing_pdf_and_unknown_template_variable(repo, tmp_path):
    (repo / "courses/demo/texts/bienvenida.md").write_text("Hola {{nope}}", encoding="utf-8")
    with pytest.raises(b.BuildError, match="unknown template variable"):
        do_build(repo, tmp_path / "out")
    (repo / "Kit/M01/01_Doc.pdf").unlink()
    (repo / "courses/demo/texts/bienvenida.md").write_text("Hola", encoding="utf-8")
    with pytest.raises(b.BuildError, match="no pdf found"):
        do_build(repo, tmp_path / "out")


@pytest.mark.parametrize(
    "mutate,message",
    [
        (lambda q: q["questions"][1].update(key="M01-Q01"), "duplicate question key"),
        (lambda q: q["questions"][1].update(key="M02-Q02"), "does not start with M01-"),
        (lambda q: q.update(module="M02"), "expected M01"),
        (lambda q: q["questions"][0].update(answer="e"), "does not match"),
        (lambda q: [x.update(retired=True) for x in q["questions"]], "no active questions"),
    ],
)
def test_bad_question_bank_is_rejected(repo, tmp_path, mutate, message):
    q = copy.deepcopy(QUESTIONS)
    mutate(q)
    write_yaml(repo / "courses/demo/questions/M01.yaml", q)
    with pytest.raises(b.BuildError, match=message):
        do_build(repo, tmp_path / "out")


def test_source_pdf_drift_warns_and_strict_fails(repo, tmp_path):
    q = copy.deepcopy(QUESTIONS)
    q["source"] = {"file": "Kit/M01/01_Doc.pdf", "sha256": "0" * 64}
    write_yaml(repo / "courses/demo/questions/M01.yaml", q)
    _, warnings = do_build(repo, tmp_path / "out")
    assert any("source PDF changed" in w for w in warnings)
    rc = b.main(["--repo-root", str(repo), "--course", "demo", "--start", "2026-10-01", "--out", str(tmp_path / "o2"), "--strict"])
    assert rc == 1


def test_cli_requires_start_and_validate_only_needs_none(repo, tmp_path):
    assert b.main(["--repo-root", str(repo), "--course", "demo", "--validate-only", "--out", str(tmp_path / "o")]) == 0
    assert not (tmp_path / "o" / "demo-2000").exists()  # validate-only writes nothing
    with pytest.raises(SystemExit):
        b.main(["--repo-root", str(repo), "--course", "demo"])


def test_rebuild_wipes_stale_files_in_its_own_dir_only(repo, tmp_path):
    out = tmp_path / "out"
    do_build(repo, out)
    stale = out / "demo-2026" / "files" / "stale.txt"
    stale.write_text("x")
    sibling = out / "keep.txt"
    sibling.write_text("y")
    rc = b.main(["--repo-root", str(repo), "--course", "demo", "--start", "2026-10-01", "--out", str(out)])
    assert rc == 0 and not stale.exists() and sibling.exists()


# ------------------------------------------------------------------ hardening: shuffle rule, path confinement, safe rebuild
@pytest.mark.parametrize("shuffle", [True, None])
def test_position_dependent_options_must_not_shuffle(repo, tmp_path, shuffle):
    q = copy.deepcopy(QUESTIONS)
    q["questions"][0]["options"]["d"] = "La respuesta «c» y los ficheros Word."
    if shuffle is None:
        del q["questions"][0]["shuffle"]
    write_yaml(repo / "courses/demo/questions/M01.yaml", q)
    with pytest.raises(b.BuildError, match="shuffle: false"):
        do_build(repo, tmp_path / "out")
    q["questions"][0]["shuffle"] = False
    write_yaml(repo / "courses/demo/questions/M01.yaml", q)
    do_build(repo, tmp_path / "out")


@pytest.mark.parametrize(
    "mutate",
    [
        lambda c, repo: c["modules"][0].update(files={"pdf": "/etc/hostname"}),
        lambda c, repo: c["modules"][0].update(files={"pdf": "../../../../etc/hostname"}),
        lambda c, repo: c["modules"][0].update(files={"ficha": "../../../../etc/hostname"}),
        lambda c, repo: c["modules"][0].update(files={"consejos": "../../../.."}),
        lambda c, repo: c["modules"][0].update(files={"posters": "/etc"}),
        lambda c, repo: c["modules"][0].update(files={"presentacion": "../../../../etc/hostname"}),
        lambda c, repo: c["welcome"].update(text="../../../../etc/hostname"),
        lambda c, repo: c["modules"][0].update(questions="../../../../etc/hostname"),
        lambda c, repo: c.update(source_root="../../.."),
    ],
)
def test_overrides_cannot_escape_their_root(repo, tmp_path, mutate):
    edit_course(repo, lambda c: mutate(c, repo))
    with pytest.raises(b.BuildError, match="escapes"):
        do_build(repo, tmp_path / "out")


def test_question_source_file_cannot_escape_source_root(repo, tmp_path):
    q = copy.deepcopy(QUESTIONS)
    q["source"] = {"file": "../../../../etc/hostname", "sha256": "0" * 64}
    write_yaml(repo / "courses/demo/questions/M01.yaml", q)
    with pytest.raises(b.BuildError, match="escapes"):
        do_build(repo, tmp_path / "out")


def test_symlinked_sources_are_refused(repo, tmp_path):
    secret = tmp_path / "secret.pdf"
    secret.write_bytes(b"%PDF-1.4 secret")
    (repo / "Kit/M01/01_Doc.pdf").unlink()
    (repo / "Kit/M01/01_Doc.pdf").symlink_to(secret)
    with pytest.raises(b.BuildError, match="symlink|escapes"):
        do_build(repo, tmp_path / "out")


def test_mistyped_override_folder_is_an_error_not_a_silent_skip(repo, tmp_path):
    edit_course(repo, lambda c: c["modules"][0].update(files={"consejos": "Consjos"}))
    with pytest.raises(b.BuildError, match="not a folder"):
        do_build(repo, tmp_path / "out")


def _skip_on_case_insensitive_fs(path):
    """c1.PNG and c1.png are two files only on a case-sensitive filesystem (Linux); on macOS (APFS default) they are one."""
    probe = path / "CaseProbe"
    probe.write_text("x")
    try:
        if (path / "caseprobe").exists():
            pytest.skip("case-insensitive filesystem: c1.PNG and c1.png are the same file here")
    finally:
        probe.unlink()


def test_output_name_collisions_are_detected(repo, tmp_path):
    _skip_on_case_insensitive_fs(tmp_path)
    make_png(repo / "Kit/M01/Consejos/c1.jpg", (100, 100))  # converted/copied name would be c1.* twice
    (repo / "Kit/M01/Consejos/c1.PNG").write_bytes((repo / "Kit/M01/Consejos/c1.png").read_bytes())
    with pytest.raises(b.BuildError, match="collision"):
        do_build(repo, tmp_path / "out")


def test_validate_reports_the_collisions_a_build_would_fail_on(repo, tmp_path, capsys):
    _skip_on_case_insensitive_fs(tmp_path)
    """`make validate` is the gate before a build: it must not pass a kit the build then rejects."""
    make_png(repo / "Kit/M01/Consejos/c1.jpg", (100, 100))
    (repo / "Kit/M01/Consejos/c1.PNG").write_bytes((repo / "Kit/M01/Consejos/c1.png").read_bytes())
    argv = ["--repo-root", str(repo), "--course", "demo", "--out", str(tmp_path / "o")]
    assert b.main([*argv, "--validate-only"]) == 1 and "collision" in capsys.readouterr().err
    assert b.main([*argv, "--start", "2026-10-01"]) == 1 and "collision" in capsys.readouterr().err


def test_validate_and_build_agree_on_image_output_names(repo, tmp_path):
    """A big image becomes <stem>.jpg, a small one keeps its name: the dry run must predict exactly that."""
    dry, _, _ = b.build(repo, "demo", 2026, "2026-10-01", tmp_path / "dry", dry=True)
    real, _ = do_build(repo, tmp_path / "out")
    names = lambda m: [(f["filename"], f["mime"]) for s in m["sections"] for i in s["items"] for f in i.get("files", [])]
    assert names(dry) == names(real) and ("c1.png", "image/png") in names(real) and ("p1.jpg", "image/jpeg") in names(real)
    assert not (tmp_path / "dry").exists(), "a dry run writes nothing"


@pytest.mark.parametrize(
    "mutate,message",
    [
        (lambda c, repo: c["welcome"].update(posters="Kit/Cartels"), "welcome.posters is not a folder"),
        (lambda c, repo: c["welcome"].update(trypticos="Kit/Triptics"), "welcome.trypticos is not a folder"),
        (lambda c, repo: (repo / "Kit/Tripticos/t1.pdf").unlink(), "welcome.trypticos has no PDF files"),
        (lambda c, repo: (repo / "Kit/Carteles/inicio.png").unlink(), "welcome.posters has no images"),
    ],
)
def test_missing_or_empty_welcome_folder_is_an_error_not_a_silent_skip(repo, tmp_path, mutate, message):
    edit_course(repo, lambda c: mutate(c, repo))
    with pytest.raises(b.BuildError, match=message):
        do_build(repo, tmp_path / "out")


def test_validate_only_leaves_a_running_builds_temp_folder_alone(repo, tmp_path):
    out = tmp_path / "out"
    running = out / ".tmp-demo-2000"  # what a concurrent `make build` of the same course and cycle is writing into
    (running / "files").mkdir(parents=True)
    (running / "files" / "half-written.pdf").write_bytes(b"x")
    assert b.main(["--repo-root", str(repo), "--course", "demo", "--validate-only", "--out", str(out)]) == 0
    assert (running / "files" / "half-written.pdf").exists()


def test_oversized_image_is_an_error_message_not_a_traceback(repo, tmp_path, monkeypatch, capsys):
    monkeypatch.setattr(b.Image, "MAX_IMAGE_PIXELS", 1000)  # the 2400x3000 poster is now a "decompression bomb"
    rc = b.main(["--repo-root", str(repo), "--course", "demo", "--start", "2026-10-01", "--out", str(tmp_path / "o")])
    assert rc == 1 and "ERROR" in capsys.readouterr().err


def test_three_digit_question_keys_validate_and_build(repo, tmp_path):
    """questions.schema.json allows Q001..Q999; the manifest contract must accept what the bank schema accepts."""
    q = copy.deepcopy(QUESTIONS)
    q["questions"][0]["key"] = "M01-Q100"
    write_yaml(repo / "courses/demo/questions/M01.yaml", q)
    m, _ = do_build(repo, tmp_path / "out")
    assert item(m, ":M01:quiz")["questions"][0]["key"] == "M01-Q100"


def test_manifest_contract_requires_exactly_one_kind_of_deadline(repo, tmp_path):
    m, _ = do_build(repo, tmp_path / "out")
    b.check_schema(m, "manifest.schema.json", "manifest")
    both = copy.deepcopy(m)
    both["completion"]["schedule"] = "monthly"
    neither = copy.deepcopy(m)
    del neither["completion"]["due_days"]
    for broken in (both, neither):
        with pytest.raises(b.BuildError, match="completion"):
            b.check_schema(broken, "manifest.schema.json", "manifest")


def test_course_without_welcome_is_a_schema_error(repo, tmp_path):
    edit_course(repo, lambda c: c.pop("welcome"))
    with pytest.raises(b.BuildError, match="welcome"):
        do_build(repo, tmp_path / "out")


@pytest.mark.parametrize("args", [["--course", "../x"], ["--course", "Demo"], ["--course", "demo", "--start", "20261001"],
                                  ["--course", "demo", "--start", "2026-W40-4"]])
def test_cli_rejects_odd_course_and_start_values(repo, tmp_path, args):
    with pytest.raises(SystemExit):
        b.main(["--repo-root", str(repo), "--out", str(tmp_path / "o"), *args])


def test_failed_rebuild_keeps_the_last_good_build(repo, tmp_path):
    out = tmp_path / "out"
    argv = ["--repo-root", str(repo), "--course", "demo", "--start", "2026-10-01", "--out", str(out)]
    assert b.main(argv) == 0
    good = tree(out / "demo-2026")
    (repo / "Kit/M01/01_Doc.pdf").unlink()  # the next build fails: no PDF
    assert b.main(argv) == 1
    assert tree(out / "demo-2026") == good
    assert not (out / ".tmp-demo-2026").exists()


def test_html_special_characters_in_variables_are_escaped(repo, tmp_path):
    edit_course(repo, lambda c: c.update(title="Curso <b>&</b> prueba"))
    m, _ = do_build(repo, tmp_path / "out")
    html = m["sections"][0]["summary_html"]
    assert "<b>" not in html and "&lt;b&gt;" in html


# ------------------------------------------------------------------ the real kit (kit/ is local, imported by `make setup`)
needs_kit = pytest.mark.skipif(
    not (REPO / "kit" / "RecursosFormativos").is_dir(),
    reason="kit/ not imported: download INCIBE's zip into upload/ and run `make setup`",
)


@needs_kit
def test_real_course_validates():
    rc = b.main(["--repo-root", str(REPO), "--course", "concienciacion", "--validate-only"])
    assert rc == 0


@needs_kit
@pytest.mark.slow
def test_real_kit_builds_deterministically(tmp_path):
    a, bb = tmp_path / "a", tmp_path / "b"
    for out in (a, bb):
        assert b.main(["--repo-root", str(REPO), "--course", "concienciacion", "--start", "2026-10-01", "--out", str(out), "--strict"]) == 0
    ma = json.loads((a / "concienciacion-2026/manifest.json").read_text(encoding="utf-8"))
    mb = json.loads((bb / "concienciacion-2026/manifest.json").read_text(encoding="utf-8"))
    assert ma == mb
    ta, tb = tree(a / "concienciacion-2026"), tree(bb / "concienciacion-2026")
    assert ta.keys() == tb.keys()
    assert all(ta[k] == tb[k] for k in ta)
    quizzes = [i for s in ma["sections"] for i in s["items"] if i["type"] == "quiz"]
    assert len(quizzes) == 9 and sum(len(q["questions"]) for q in quizzes) == 90
    # web copies of the 300 dpi posters must be small
    images = [f for s in ma["sections"] for i in s["items"] for f in i.get("files", []) if f["mime"].startswith("image/")]
    assert images and max(f["size"] for f in images) < 1_000_000
