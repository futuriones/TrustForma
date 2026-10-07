"""Content-lifecycle acceptance test against the RUNNING local Moodle (`make test-e2e`; needs `make up install configure`).

It builds an isolated synthetic course (never touching the real kit or the real course) and drives the real toolchain
(`make build / plan / deploy`) plus real learners, to prove the invariants that make yearly re-use safe:
progress survives content updates, old attempts keep the question version they were shown, changes that would re-score
existing attempts are blocked, removed modules are hidden and not deleted, and a new cycle is isolated from the old one.
"""
import copy
import json
import shutil
import subprocess
import urllib.parse
import urllib.request
from datetime import date, timedelta
from pathlib import Path

import pytest
import yaml
from PIL import Image

REPO = Path(__file__).resolve().parents[3]
pytestmark = pytest.mark.e2e

COURSE_ID = "e2etest"
KIT = REPO / "build" / "e2e-src"
COURSE_DIR = REPO / "courses" / COURSE_ID
COMPOSE = ["docker", "compose", "-f", str(REPO / "lms/docker/compose.alpine.yaml"), "--env-file", str(REPO / "lms/docker/.env")]  # as the Makefile
ACTIONS = {"create", "update", "hide", "link", "retire", "blocked"}
COMPLETE, COMPLETE_PASS = 1, 2  # Moodle COMPLETION_COMPLETE / COMPLETION_COMPLETE_PASS


# GNU make 4+: `gmake` on macOS (Apple's make is 3.81, which the Makefile refuses), `make` on Linux.
MAKE = "gmake" if shutil.which("gmake") else "make"


def make(*args, check=True):
    proc = subprocess.run([MAKE, "-C", str(REPO), *args], capture_output=True, text=True)
    if check and proc.returncode:
        raise AssertionError(f"make {' '.join(args)} failed ({proc.returncode}):\n{proc.stdout}\n{proc.stderr}")
    return proc


def moodle(*php_args):
    return subprocess.run(COMPOSE + ["exec", "-T", "-u", "www-data", "moodle", "php", *php_args], capture_output=True, text=True)


def scenario(idnumber, do, **kv):
    args = ["local/awarenesssync/cli/e2e_scenario.php", f"--idnumber={idnumber}", f"--do={do}"] + [f"--{k}={v}" for k, v in kv.items()]
    proc = moodle(*args)
    assert proc.returncode == 0, f"scenario {do} failed:\n{proc.stdout}\n{proc.stderr}"
    return json.loads(proc.stdout.strip().splitlines()[-1])


def actions(proc):
    found = set()
    for line in (proc.stdout + proc.stderr).splitlines():
        parts = line.split()
        if len(parts) >= 3 and parts[0] in ACTIONS:
            found.add((parts[0], parts[1], parts[2]))
    return found


def at(days: int, hms: str = "23:59:59") -> str:
    """A moment `days` from today (server local time as strtotime reads it): keeps the test independent of the calendar."""
    return f"{(date.today() + timedelta(days=days)).isoformat()} {hms}"


MAIL_QUERY = 'subject:"Modulo uno vence en 7"'  # the 7-day reminder of the synthetic course


def mailpit_port() -> str:
    """Host port of the Mailpit UI: MAILPIT_PORT from lms/docker/.env (default 8025, the compose default)."""
    env = REPO / "lms/docker/.env"
    if env.exists():
        for line in env.read_text(encoding="utf-8").splitlines():
            if line.startswith("MAILPIT_PORT="):
                return line.split("=", 1)[1].strip().strip("\"'") or "8025"
    return "8025"


def mailpit_count(query: str):
    """Messages in the local Mailpit matching a search query, or None when its API is not reachable from where pytest runs."""
    try:
        with urllib.request.urlopen(f"http://localhost:{mailpit_port()}/api/v1/search?query=" + urllib.parse.quote(query), timeout=5) as r:
            return json.load(r)["messages_count"]  # matches only ("total" is the whole mailbox)
    except OSError:
        return None


def key(cycle, module, part):
    return f"{COURSE_ID}:{cycle}:{module}:{part}"


QUESTIONS = {
    "M01": ["Texto original", "Segunda pregunta", "Tercera pregunta"],
    "M02": ["Pregunta del modulo dos", "Otra del modulo dos"],
}


def bank(module, texts, retire=()):
    return {
        "module": module,
        "questions": [
            {"key": f"{module}-Q{i:02d}", "text": t, "options": {"a": "A", "b": "B", "c": "C", "d": "D"},
             "answer": "abcd"[i % 4], "shuffle": True, **({"retired": True} if f"{module}-Q{i:02d}" in retire else {})}
            for i, t in enumerate(texts, 1)
        ],
    }


COURSE = {
    "id": COURSE_ID, "title": "Curso E2E", "shortname_prefix": "E2E", "category": "E2E", "source_root": "../../build/e2e-src", "lang": "es",
    "completion": {"due_days": 45, "require_pdf_view": True},  # 45: start 2026-10-01 -> 15 Nov, unlike any monthly deadline
    "quiz_defaults": {"pass_percent": 80, "attempts": 0, "grademethod": "highest", "shuffle_questions": True, "review": "correctness"},
    "certificate": {"template": "concienciacion", "validity_months": 12}, "enrol": {"cohort": "e2e-test"},  # never the real employees
    "welcome": {"text": "texts/bienvenida.md"},
    "modules": [
        {"key": "M01", "title": "Modulo uno", "folder": "Kit/M01", "questions": "questions/M01.yaml"},
        {"key": "M02", "title": "Modulo dos", "folder": "Kit/M02", "questions": "questions/M02.yaml"},
    ],
}


def write(path: Path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(yaml.safe_dump(data, allow_unicode=True, sort_keys=False), encoding="utf-8")


def write_kit(pdf: bytes, modules=("M01", "M02")):
    for m in modules:
        (KIT / "Kit" / m).mkdir(parents=True, exist_ok=True)
        (KIT / "Kit" / m / f"{m}_documento.pdf").write_bytes(pdf + m.encode())
    Image.new("RGB", (100, 100), (10, 120, 200)).save(KIT / "Kit" / "M01" / "unused.png")  # ignored: not in Consejos/


def write_course(modules=("M01", "M02"), retire=(), texts=None, require_view=True, monthly=False, due_month=None, validity_months=None):
    course = copy.deepcopy(COURSE)
    if validity_months:
        course["certificate"]["validity_months"] = validity_months
    course["completion"]["require_pdf_view"] = require_view
    course["modules"] = [m for m in course["modules"] if m["key"] in modules]
    if monthly:  # per-module deadlines instead of one course deadline
        course["completion"] = {"schedule": {"mode": "monthly"}, "require_pdf_view": require_view}
        for m in course["modules"]:
            if m["key"] in (due_month or {}):
                m["due_month"] = due_month[m["key"]]
    write(COURSE_DIR / "course.yaml", course)
    (COURSE_DIR / "texts").mkdir(parents=True, exist_ok=True)
    (COURSE_DIR / "texts" / "bienvenida.md").write_text("## Bienvenida {{cycle}}\n\nDebes aprobar con {{pass_percent}} %.\n", encoding="utf-8")
    for m in ("M01", "M02"):
        write(COURSE_DIR / "questions" / f"{m}.yaml", bank(m, (texts or {}).get(m, QUESTIONS[m]), retire))


@pytest.fixture(scope="module")
def stack():
    if not (REPO / "lms/docker/.env").exists() or not subprocess.run(COMPOSE + ["ps", "-q", "moodle"], capture_output=True, text=True).stdout.strip():
        pytest.skip("Moodle stack is not running: `make up install configure`")
    if make("status", check=False).stdout.count("installed:") == 0:
        pytest.skip("Moodle is not installed: `make install configure`")
    scenario(f"{COURSE_ID}:2026", "ensure-cohort")
    yield
    for cycle in (2026, 2027):  # always leave the pilot clean, even if the test failed midway
        idn = f"{COURSE_ID}:{cycle}"
        moodle("local/awarenesssync/cli/e2e_scenario.php", f"--idnumber={idn}", "--do=cleanup")
        moodle("local/awarenesssync/cli/e2e_scenario.php", f"--idnumber={idn}", "--do=delete-course")
    for p in (COURSE_DIR, KIT, REPO / "build" / f"{COURSE_ID}-2026", REPO / "build" / f"{COURSE_ID}-2027"):
        shutil.rmtree(p, ignore_errors=True)


def test_content_lifecycle(stack):
    c26, c27 = f"{COURSE_ID}:2026", f"{COURSE_ID}:2027"
    pdf1, quiz1 = key(2026, "M01", "pdf"), key(2026, "M01", "quiz")

    # -- 1. first deploy creates everything, and a second one changes nothing
    write_kit(b"%PDF-1.4 edition one ")
    write_course()
    make("build", f"COURSE={COURSE_ID}", "START=2026-10-01")
    assert ("create", "course", c26) in actions(make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01"))
    make("deploy", f"COURSE={COURSE_ID}", "START=2026-10-01")
    again = make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01", "EXTRA=--fail-on-changes", check=False)
    assert again.returncode == 0 and "nothing to do" in again.stdout, f"not idempotent:\n{again.stdout}"
    first = scenario(c26, "deploys")
    assert first["count"] == 1 and first["version"], "a deploy that ran to its end leaves one marker with its content edition"
    make("deploy", f"COURSE={COURSE_ID}", "START=2026-10-01")
    assert scenario(c26, "deploys") == first, "a deploy with nothing to do records nothing"
    assert scenario(c26, "unmanaged")["refused"] is True, "the API must only answer for courses this plugin deployed"

    # -- 2. learners make progress
    scenario(c26, "setup", pdf=pdf1, quiz=quiz1)
    before = scenario(c26, "facts", pdf=pdf1, quiz=quiz1, question="M01-Q01")
    assert before["keeper"]["pdf"] == COMPLETE and before["keeper"]["quiz"] == COMPLETE_PASS
    assert before["keeper"]["attempts"] == 1 and before["keeper"]["grade"] == 10
    assert before["keeper"]["first_attempt_text"] == "Texto original"
    assert before["starter"]["pdf"] == COMPLETE and before["starter"]["quiz"] == 0

    # -- 3. a content update (reworded question + new PDF edition) touches exactly those items
    write_kit(b"%PDF-1.4 edition two ", modules=("M01",))
    write_course(texts={"M01": ["Texto corregido", "Segunda pregunta", "Tercera pregunta"]})
    make("build", f"COURSE={COURSE_ID}", "START=2026-10-01")
    plan = actions(make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01"))
    assert plan == {("update", "question", "M01-Q01"), ("update", "quiz", quiz1), ("update", "resource", pdf1)}, plan
    make("deploy", f"COURSE={COURSE_ID}", "START=2026-10-01")
    second = scenario(c26, "deploys")
    assert second["count"] == 2 and second["version"] != first["version"], "the API reports the edition of the last completed deploy"

    # -- 4. ...and nobody loses progress; old attempts keep the version they saw, new attempts get the new one
    after = scenario(c26, "facts", pdf=pdf1, quiz=quiz1, question="M01-Q01")
    assert after["keeper"]["pdf"] == COMPLETE, "replacing the PDF must not erase who had viewed it"
    assert after["keeper"]["quiz"] == COMPLETE_PASS and after["keeper"]["grade"] == 10 and after["keeper"]["attempts"] == 1
    assert after["keeper"]["first_attempt_text"] == "Texto original", "old attempt must keep the question version it was shown"
    assert after["starter"]["pdf"] == COMPLETE
    fresh = scenario(c26, "attempt", user="newcomer", quiz=quiz1, question="M01-Q01", correct=1)
    assert fresh["text"] == "Texto corregido" and fresh["grade"] == 10, "new attempts must use the latest question version"
    assert scenario(c26, "inspect-quiz", quiz=quiz1, question="M01-Q01")["versions"] == 2
    assert make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01", "EXTRA=--fail-on-changes", check=False).returncode == 0

    # -- 5. changes that would re-score existing attempts are blocked, and nothing is written
    t3 = ["Texto corregido", "Segunda pregunta", "Tercera pregunta"]
    t4 = t3 + ["Cuarta pregunta"]
    write_course(retire=("M01-Q02",), texts={"M01": t3})
    make("build", f"COURSE={COURSE_ID}", "START=2026-10-01")
    blocked = make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01", check=False)
    assert blocked.returncode != 0 and ("blocked", "quiz", quiz1) in actions(blocked), blocked.stdout
    for extra in ("EXTRA=", "EXTRA=--allow-structure-change"):  # retiring in an attempted quiz is impossible, flag or not
        refused = make("deploy", f"COURSE={COURSE_ID}", "START=2026-10-01", extra, check=False)
        assert refused.returncode != 0, "deploy must refuse to retire a question from an attempted quiz"
        assert scenario(c26, "inspect-quiz", quiz=quiz1, question="M01-Q02")["slots"] == 3, "a refused deploy must write nothing"
    # adding a question re-scores old attempts too: blocked by default, allowed only on explicit request
    write_course(texts={"M01": t4})
    make("build", f"COURSE={COURSE_ID}", "START=2026-10-01")
    assert make("deploy", f"COURSE={COURSE_ID}", "START=2026-10-01", check=False).returncode != 0
    assert scenario(c26, "inspect-quiz", quiz=quiz1, question="M01-Q04")["slots"] == 3
    make("deploy", f"COURSE={COURSE_ID}", "START=2026-10-01", "EXTRA=--allow-structure-change")
    assert scenario(c26, "inspect-quiz", quiz=quiz1, question="M01-Q04")["slots"] == 4
    assert scenario(c26, "facts", pdf=pdf1, quiz=quiz1, question="M01-Q01")["keeper"]["attempts"] == 1, "attempts are never deleted"
    # a quiz nobody has attempted yet can be restructured freely: retiring hides the question, removes its slot
    write_course(retire=("M02-Q02",), texts={"M01": t4})
    make("build", f"COURSE={COURSE_ID}", "START=2026-10-01")
    make("deploy", f"COURSE={COURSE_ID}", "START=2026-10-01")
    gone = scenario(c26, "inspect-quiz", quiz=key(2026, "M02", "quiz"), question="M02-Q02")
    assert gone["slots"] == 1 and gone["hidden"] is True

    # -- 6. removing a module hides it (with its history) and never deletes it
    write_course(modules=("M01",), retire=("M02-Q02",), texts={"M01": t4})
    make("build", f"COURSE={COURSE_ID}", "START=2026-10-01")
    plan = actions(make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01"))
    assert {("hide", "section", f"{c26}:M02"), ("hide", "resource", key(2026, "M02", "pdf")), ("hide", "quiz", key(2026, "M02", "quiz"))} <= plan
    assert not any(a[0] == "create" for a in plan)
    make("deploy", f"COURSE={COURSE_ID}", "START=2026-10-01")
    cms = scenario(c26, "cms")
    assert cms[key(2026, "M02", "pdf")] == 0 and cms[key(2026, "M02", "quiz")] == 0, "removed activities are hidden"
    assert cms[pdf1] == 1 and cms[quiz1] == 1, "the rest stays visible"

    # -- 6b. a module that comes back into the manifest is shown again (its content is unchanged: only visibility differs)
    write_course(retire=("M02-Q02",), texts={"M01": t4})
    make("build", f"COURSE={COURSE_ID}", "START=2026-10-01")
    plan = actions(make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01"))
    assert {("update", "section", f"{c26}:M02"), ("update", "resource", key(2026, "M02", "pdf")),
            ("update", "quiz", key(2026, "M02", "quiz"))} <= plan, plan
    assert not any(a[0] == "create" for a in plan)
    make("deploy", f"COURSE={COURSE_ID}", "START=2026-10-01")
    cms = scenario(c26, "cms")
    assert cms[key(2026, "M02", "pdf")] == 1 and cms[key(2026, "M02", "quiz")] == 1, "a re-added item must not stay hidden"
    assert make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01", "EXTRA=--fail-on-changes", check=False).returncode == 0

    # -- 6c. changing the completion rule of an activity learners already used would wipe their state: blocked
    write_course(retire=("M02-Q02",), texts={"M01": t4}, require_view=False)
    make("build", f"COURSE={COURSE_ID}", "START=2026-10-01")
    blocked = make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01", check=False)
    assert blocked.returncode != 0 and ("blocked", "resource", pdf1) in actions(blocked), blocked.stdout
    assert make("deploy", f"COURSE={COURSE_ID}", "START=2026-10-01", check=False).returncode != 0
    assert scenario(c26, "facts", pdf=pdf1, quiz=quiz1, question="M01-Q01")["keeper"]["pdf"] == COMPLETE, "a refused deploy must write nothing"
    write_course(retire=("M02-Q02",), texts={"M01": t4})
    make("build", f"COURSE={COURSE_ID}", "START=2026-10-01")
    assert make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01", "EXTRA=--fail-on-changes", check=False).returncode == 0

    # -- 6d. Deadlines and quiz review options are INITIAL defaults: the administrator owns them in Moodle afterwards, and a
    #        deploy must never overwrite them. (The admin's GUI action is simulated as Moodle itself stores it.)
    quiz2 = key(2026, "M02", "quiz")
    due = scenario(c26, "due")
    assert due[quiz1].startswith("2026-11-15") and due[quiz2].startswith("2026-11-15"), "created with the manifest's default deadline"
    assert due[pdf1] is None, "the PDF carries no date: each module has ONE date to manage, on its quiz"
    pre = scenario(c26, "facts", pdf=pdf1, quiz=quiz1, question="M01-Q01")["keeper"]  # grade is 7.5 here: step 5 added a 4th question
    # changing the repo's schedule for an EXISTING cycle changes no deadline and touches no activity
    write_course(retire=("M02-Q02",), texts={"M01": t4}, monthly=True)
    make("build", f"COURSE={COURSE_ID}", "START=2026-10-01")
    found = actions(make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01"))
    assert not {a for a in found if a[1] in ("quiz", "resource") or a[0] in ("blocked", "create", "hide", "retire")}, found
    make("deploy", f"COURSE={COURSE_ID}", "START=2026-10-01")
    due = scenario(c26, "due")
    assert due[quiz1].startswith("2026-11-15") and due[quiz2].startswith("2026-11-15"), "the repo default must not overwrite existing dates"
    assert make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01", "EXTRA=--fail-on-changes", check=False).returncode == 0
    # the administrator edits quiz 1 in Moodle (Expect completed on + Review options)
    scenario(c26, "setdue", quiz=quiz1, date="2026-12-05 23:59:59")
    scenario(c26, "setreview", quiz=quiz1)
    assert scenario(c26, "facts", pdf=pdf1, quiz=quiz1, question="M01-Q01")["keeper"]["review"]["rightanswer"] is True
    assert make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01", "EXTRA=--fail-on-changes", check=False).returncode == 0, "a GUI edit is not drift"
    # a content update that DOES re-save quiz 1 keeps the admin's date and review options, and nobody loses progress
    t4b = [t4[0], t4[1], "Tercera pregunta revisada", t4[3]]
    write_course(retire=("M02-Q02",), texts={"M01": t4b}, monthly=True)
    make("build", f"COURSE={COURSE_ID}", "START=2026-10-01")
    found = actions(make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01"))
    assert ("update", "quiz", quiz1) in found and not {a for a in found if a[0] in ("blocked", "create", "hide", "retire")}, found
    make("deploy", f"COURSE={COURSE_ID}", "START=2026-10-01")
    assert scenario(c26, "due")[quiz1].startswith("2026-12-05"), "deploy must not revert the administrator's deadline"
    kept = scenario(c26, "facts", pdf=pdf1, quiz=quiz1, question="M01-Q01")
    assert kept["keeper"]["review"] == {"marks": True, "correctness": True, "rightanswer": True, "feedback": False}, "review options kept"
    assert kept["keeper"]["pdf"] == COMPLETE and kept["keeper"]["quiz"] == COMPLETE_PASS, "changing deadlines must not erase progress"
    assert kept["keeper"]["attempts"] == pre["attempts"] == 1 and kept["keeper"]["grade"] == pre["grade"], "deadlines must not re-score anyone"
    assert kept["starter"]["pdf"] == COMPLETE
    assert make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01", "EXTRA=--fail-on-changes", check=False).returncode == 0

    # -- 6d2. re-applying the certificate design while certificates are already issued is allowed, but the plan must say so
    #         (mod_customcert draws every certificate from the CURRENT template). Plan only: nothing is deployed, so the
    #         steps below see the same course as before.
    write_course(retire=("M02-Q02",), texts={"M01": t4b}, monthly=True, validity_months=24)
    make("build", f"COURSE={COURSE_ID}", "START=2026-10-01")
    nothing_issued = make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01")
    assert any(a[0] == "update" and a[1] == "certificate" for a in actions(nothing_issued)), nothing_issued.stdout
    assert "already issued" not in nothing_issued.stdout + nothing_issued.stderr, "no certificate exists yet: nothing to warn about"
    assert scenario(c26, "issue-certificate", user="keeper")["issued"]
    warned = make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01")
    assert warned.returncode == 0 and "1 certificate(s) already issued" in warned.stdout + warned.stderr, warned.stdout
    write_course(retire=("M02-Q02",), texts={"M01": t4b}, monthly=True)
    make("build", f"COURSE={COURSE_ID}", "START=2026-10-01")
    assert make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01", "EXTRA=--fail-on-changes", check=False).returncode == 0

    # -- 6e. CISO Assistant sees a per-user compliance state that follows the dates the administrator set
    #        (dates are relative to today so the test does not rot; the wide reminder windows below tolerate a DST change)
    # Only `starter` (has passed nothing) has a state that cannot depend on when cron aggregated course completion in step 6.
    users = ("starter",)
    states = lambda: {scenario(c26, "compliance")[u] for u in users}
    scenario(c26, "setdue", quiz=quiz1, date=at(100))
    scenario(c26, "setdue", quiz=quiz2, date=at(70))
    scenario(c26, "setenddate", date=at(100))
    assert states() == {"on_track"}, scenario(c26, "compliance")
    scenario(c26, "setdue", quiz=quiz2, date=at(-30))  # a missed module deadline: nobody has passed quiz 2
    assert states() == {"degraded"}
    assert scenario(c26, "compliance")["_totals"]["degraded"] >= 1
    scenario(c26, "setdue", quiz=quiz1, date=at(-20))  # ...and the whole programme is over
    scenario(c26, "setenddate", date=at(-20))
    assert states() == {"failed"}
    scenario(c26, "setdue", quiz=quiz1, date=at(30))  # the administrator extends module 1 beyond the course end date
    assert states() == {"degraded"}, "the programme is over at its LAST deadline, whichever module holds it (here the first one)"
    scenario(c26, "setdue", quiz=quiz1, date=at(100))
    scenario(c26, "setdue", quiz=quiz2, date=at(70))
    scenario(c26, "setenddate", date=at(100))
    assert states() == {"on_track"}

    # -- 6f. reminders follow the native dates: stateless windows, only learners who have not passed, a moved deadline re-targets
    def remind(start, end, **kv):
        return sorted(scenario(c26, "remind", **{"from": start, "date": end}, **kv))
    assert remind(at(92, "12:00:00"), at(94, "12:00:00")) == ["starter:before7"], "keeper and newcomer passed quiz 1"
    assert remind(at(98, "12:00:00"), at(100, "12:00:00")) == ["starter:before1"]
    assert remind(at(100, "12:00:00"), at(101, "12:00:00")) == ["starter:overdue"]
    assert remind(at(100, "12:00:00"), at(101, "12:00:00"), overdue=0) == []
    # after a gap between runs (cron was down) nobody is told "due in N days" about a deadline that has already passed,
    # and of several "before" moments in one window only the nearest is sent
    assert remind(at(92, "12:00:00"), at(101, "12:00:00")) == ["starter:overdue"]
    assert remind(at(92, "12:00:00"), at(100, "12:00:00")) == ["starter:before1"]
    assert remind(at(10, "00:00:00"), at(11, "00:00:00")) == []
    scenario(c26, "setdue", quiz=quiz1, date=at(120))  # the administrator moves the deadline
    assert remind(at(92, "12:00:00"), at(101, "12:00:00")) == [], "the old moments no longer apply"
    assert remind(at(112, "12:00:00"), at(114, "12:00:00")) == ["starter:before7"], "the new deadline re-arms the reminders"
    before_mail = mailpit_count(MAIL_QUERY)
    remind(at(112, "12:00:00"), at(114, "12:00:00"), send=1)
    if before_mail is not None:
        assert mailpit_count(MAIL_QUERY) == before_mail + 1, "the reminder goes out by email (Mailpit)"
    scenario(c26, "setdue", quiz=quiz1, date=at(100))

    # -- 6g. the evidence keeps people who can no longer train: listed, flagged inactive, out of the totals
    listed = scenario(c26, "row", user="newcomer")
    assert listed["row"]["enrolment_active"] is True
    scenario(c26, "leave-cohort", user="newcomer")  # left the company: taken out of the cohort
    left = scenario(c26, "row", user="newcomer")
    assert left["row"] is not None, "a cohort leaver vanished from the report (run `make configure`: enrol_cohort/unenrolaction)"
    assert left["row"]["enrolment_active"] is False and left["row"]["modules"][0]["passed"] is True, "their results stay"
    assert left["enrolled"] == listed["enrolled"] - 1
    scenario(c26, "suspend-account", user="starter")  # account suspended, enrolment untouched
    suspended = scenario(c26, "row", user="starter")
    assert suspended["row"]["enrolment_active"] is False and suspended["enrolled"] == left["enrolled"] - 1

    # back to the single course deadline so the rest of the scenario is unchanged
    write_course(retire=("M02-Q02",), texts={"M01": t4})
    make("build", f"COURSE={COURSE_ID}", "START=2026-10-01")
    make("deploy", f"COURSE={COURSE_ID}", "START=2026-10-01")
    assert make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01", "EXTRA=--fail-on-changes", check=False).returncode == 0

    # -- 7. a new yearly cycle is a separate course; last year's evidence is untouched
    make("build", f"COURSE={COURSE_ID}", "START=2027-10-01")
    make("deploy", f"COURSE={COURSE_ID}", "START=2027-10-01")
    y27 = scenario(c27, "facts", pdf=key(2027, "M01", "pdf"), quiz=key(2027, "M01", "quiz"), question="M01-Q01")
    assert y27["keeper"]["pdf"] == 0 and y27["keeper"]["quiz"] == 0 and y27["keeper"]["attempts"] == 0, "everyone starts the new cycle from zero"
    y26 = scenario(c26, "facts", pdf=pdf1, quiz=quiz1, question="M01-Q01")
    assert y26["keeper"]["quiz"] == COMPLETE_PASS and y26["keeper"]["attempts"] == 1, "the previous cycle must be untouched"

    # -- 8. a closed cycle is frozen: plans report it as blocked and deploys are refused, with no override
    assert make("close-cycle", f"COURSE={COURSE_ID}", "CYCLE=2026").returncode == 0
    assert "already closed" in make("close-cycle", f"COURSE={COURSE_ID}", "CYCLE=2026").stdout, "closing twice is harmless"
    write_course(retire=("M02-Q02",), texts={"M01": t3 + ["Cuarta pregunta, reescrita"]})
    make("build", f"COURSE={COURSE_ID}", "START=2026-10-01")
    frozen = make("plan", f"COURSE={COURSE_ID}", "START=2026-10-01", check=False)
    assert frozen.returncode != 0 and ("blocked", "course", c26) in actions(frozen), frozen.stdout
    for extra in ("EXTRA=", "EXTRA=--allow-structure-change"):
        assert make("deploy", f"COURSE={COURSE_ID}", "START=2026-10-01", extra, check=False).returncode != 0, "closed cycles take no deploys"
    assert scenario(c26, "inspect-quiz", quiz=quiz1, question="M01-Q04")["versions"] == 1, "the closed cycle must not have changed"
    make("build", f"COURSE={COURSE_ID}", "START=2027-10-01")
    make("deploy", f"COURSE={COURSE_ID}", "START=2027-10-01")  # the next cycle still takes the change
    assert scenario(c27, "inspect-quiz", quiz=key(2027, "M01", "quiz"), question="M01-Q04")["slots"] == 4
