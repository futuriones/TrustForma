"""check_deps.py reads the hashed lock written by `make lock`: every package must be pinned exactly and carry a hash."""
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO / "lms" / "tools"))
import check_deps  # noqa: E402

LOCK = """# Written by `make lock`
attrs==26.1.0 \\
    --hash=sha256:aaaa \\
    --hash=sha256:bbbb
    # via jsonschema
pyyaml==6.0.3 \\
    --hash=sha256:cccc
    # via -r requirements.in
"""


def test_hashed_lock_is_parsed():
    found, problems = check_deps.pins(LOCK)
    assert found == {"attrs": "26.1.0", "pyyaml": "6.0.3"} and problems == []


def test_unpinned_and_unhashed_entries_are_problems():
    found, problems = check_deps.pins("requests>=2\npyyaml==6.0.3\n")
    assert found == {"pyyaml": "6.0.3"}
    assert any("requests>=2" in p for p in problems) and any("pyyaml: no --hash" in p for p in problems)


def test_the_committed_lock_covers_the_direct_requirements():
    """Every package named in requirements.in is in the lock at the same version, and the lock holds indirect ones too."""
    found, problems = check_deps.pins(check_deps.REQ.read_text(encoding="utf-8"))
    assert problems == []
    direct, _ = check_deps.pins("".join(f"{line}\n    --hash=sha256:x\n" for line in
                                        (REPO / "lms/tools/requirements.in").read_text(encoding="utf-8").splitlines()
                                        if line and not line.startswith("#")))
    assert direct and all(found.get(name.lower()) == version for name, version in direct.items())
    assert len(found) > len(direct), "the lock must also pin what the direct requirements pull in"
