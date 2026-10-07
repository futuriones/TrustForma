"""Outgoing mail is configured from lms/docker/.env only: every MOODLE_SMTP_* / MOODLE_NOREPLY variable must travel
.env.example -> the compose file -> config.php (or configure_site.php for the OAuth client), and XOAUTH2 must never get a password.
The same path is checked for MOODLE_GRC_* (the compliance API token's IP restriction, read by configure_site.php)."""
import re
from pathlib import Path

DOCKER = Path(__file__).resolve().parents[2] / "docker"
CONFIGURE = Path(__file__).resolve().parents[2] / "moodle/local_awarenesssync/cli/configure_site.php"


def names(text: str) -> set[str]:
    return set(re.findall(r"\b(MOODLE_(?:SMTP_[A-Z_]+|NOREPLY|GRC_[A-Z_]+))\b", text))


def test_every_mail_variable_reaches_the_container():
    declared = names((DOCKER / ".env.example").read_text(encoding="utf-8"))
    assert {"MOODLE_SMTP_HOST", "MOODLE_SMTP_AUTHTYPE", "MOODLE_SMTP_USER", "MOODLE_SMTP_OAUTH_CLIENT_SECRET"} <= declared
    passed = names((DOCKER / "compose.alpine.yaml").read_text(encoding="utf-8"))
    assert declared <= passed, f"compose.alpine.yaml does not pass {sorted(declared - passed)}"


def test_every_mail_variable_is_consumed():
    consumers = (DOCKER / "config.php").read_text(encoding="utf-8") + CONFIGURE.read_text(encoding="utf-8")
    unused = names((DOCKER / ".env.example").read_text(encoding="utf-8")) - names(consumers)
    assert not unused, f"declared in .env.example but read nowhere: {sorted(unused)}"


def test_xoauth2_never_falls_back_to_a_password():
    cfg = (DOCKER / "config.php").read_text(encoding="utf-8")
    assert re.search(r"smtppass\s*=\s*\$CFG->smtpauthtype === 'XOAUTH2' \? ''", cfg)
    assert "$CFG->debugsmtp = false" in cfg, "the SMTP debug log would contain the token"


def test_env_example_has_no_real_secrets():
    text = (DOCKER / ".env.example").read_text(encoding="utf-8")
    for var in ("MOODLE_SMTP_PASSWORD", "MOODLE_SMTP_OAUTH_CLIENT_SECRET", "MOODLE_SMTP_OAUTH_CLIENT_ID", "MOODLE_SMTP_USER"):
        assert re.search(rf"^{var}=$", text, re.M), f"{var} must be empty in the example"
