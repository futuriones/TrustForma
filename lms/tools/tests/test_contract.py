"""The compliance report contract: the schema is a valid schema, the published sample matches it, and check_report.py
turns Moodle's error bodies and contract violations into failing exit codes."""
import copy
import io
import json
import sys
from pathlib import Path

import pytest
from jsonschema import Draft202012Validator

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO / "lms" / "tools"))
import check_report  # noqa: E402

SAMPLE = json.loads((REPO / "docs" / "compliance-report.v1.sample.json").read_text(encoding="utf-8"))


def run(body: str, *args: str, monkeypatch) -> int:
    monkeypatch.setattr(sys, "stdin", io.StringIO(body))
    return check_report.main(list(args))


def test_schema_is_itself_valid():
    Draft202012Validator.check_schema(json.loads(check_report.SCHEMA.read_text(encoding="utf-8")))


def test_sample_matches_the_contract_and_is_self_consistent():
    assert check_report.violations(SAMPLE) == []
    active = [u for u in SAMPLE["users"] if u["enrolment_active"]]
    totals = SAMPLE["totals"]
    assert totals["enrolled"] == len(active) == sum(totals["compliance"].values())
    assert totals["enrolled"] == sum(totals[k] for k in ("completed", "in_progress", "not_started", "overdue"))
    assert totals["with_overdue_modules"] == sum(any(m["overdue"] for m in u["modules"]) for u in active)
    assert {u["email"].split("@")[1] for u in SAMPLE["users"]} == {"example.com"}, "the sample must hold invented people only"


def test_additive_fields_are_allowed():
    report = copy.deepcopy(SAMPLE)
    report["new_top_level"] = 1
    report["totals"]["new_total"] = 2
    report["users"][0]["new_user_field"] = "x"
    report["users"][0]["modules"][0]["new_module_field"] = True
    assert check_report.violations(report) == []


@pytest.mark.parametrize("mutate, where", [
    (lambda r: r.pop("totals"), "(root)"),
    (lambda r: r.update(version=2), "version"),
    (lambda r: r["users"][0].pop("enrolment_active"), "users/0"),
    (lambda r: r["users"][0].update(compliance_status="unknown"), "users/0/compliance_status"),
    (lambda r: r["users"][1]["modules"][0].update(quiz_best_grade="6"), "users/1/modules/0/quiz_best_grade"),
    (lambda r: r.update(generated_at="20 Nov 2026"), "generated_at"),
])
def test_breaking_changes_are_reported(mutate, where):
    report = copy.deepcopy(SAMPLE)
    mutate(report)
    assert any(v.startswith(where) for v in check_report.violations(report)), check_report.violations(report)


def test_exit_codes(monkeypatch, capsys):
    assert run(json.dumps(SAMPLE), monkeypatch=monkeypatch) == 0
    assert run(json.dumps(SAMPLE), "--quiet", monkeypatch=monkeypatch) == 0
    quiet_out = capsys.readouterr().out.splitlines()[-1]
    assert quiet_out.startswith("OK") and "example.com" not in quiet_out, "--quiet must not print personal data"
    assert run('{"exception": "moodle_exception", "errorcode": "invalidtoken", "message": "bad"}', monkeypatch=monkeypatch) == 1
    assert run("<html>502 Bad Gateway</html>", monkeypatch=monkeypatch) == 2
    broken = copy.deepcopy(SAMPLE)
    del broken["users"][0]["status"]
    assert run(json.dumps(broken), monkeypatch=monkeypatch) == 3
