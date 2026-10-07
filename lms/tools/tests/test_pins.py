"""Pinned versions are written in three places: plugins.lock (the record), compose.alpine.yaml (build args and image lines,
what actually builds) and the ARG defaults of Dockerfile.alpine (what a plain `docker build` would use). They must agree:
a bump made in only one of them builds something other than what the record says."""
import re
from pathlib import Path

import yaml

DOCKER = Path(__file__).resolve().parents[2] / "docker"


def lock() -> dict[str, str]:
    pairs = (line.split("=", 1) for line in (DOCKER / "plugins.lock").read_text(encoding="utf-8").splitlines()
             if line.strip() and not line.startswith("#"))
    return {k.strip(): v.strip() for k, v in pairs}


def compose() -> dict:
    return yaml.safe_load((DOCKER / "compose.alpine.yaml").read_text(encoding="utf-8"))


def test_every_pin_is_by_digest_or_commit():
    pins = lock()
    for key in ("PHP_IMAGE", "NGINX_IMAGE", "POSTGRES_IMAGE", "MAILPIT_IMAGE"):
        assert re.search(r"@sha256:[0-9a-f]{64}$", pins[key]), f"{key} must be pinned by digest"
    for key in ("MOODLE_COMMIT", "CUSTOMCERT_COMMIT"):
        assert re.fullmatch(r"[0-9a-f]{40}", pins[key]), f"{key} must be a full commit SHA"


def test_compose_build_args_match_the_lock():
    pins, args = lock(), compose()["x-build"]["args"]
    build_keys = {"PHP_IMAGE", "NGINX_IMAGE", "MOODLE_TAG", "MOODLE_COMMIT", "MOODLE_REPO", "CUSTOMCERT_TAG", "CUSTOMCERT_COMMIT", "CUSTOMCERT_REPO"}
    assert set(args) == build_keys
    assert {k: args[k] for k in build_keys} == {k: pins[k] for k in build_keys}


def test_compose_service_images_match_the_lock():
    pins, services = lock(), compose()["services"]
    assert services["db"]["image"] == pins["POSTGRES_IMAGE"]
    assert services["mailpit"]["image"] == pins["MAILPIT_IMAGE"]
    version = pins["MOODLE_TAG"].lstrip("v")
    for name in ("moodle", "cron", "web"):
        assert f":{version}" in services[name]["image"], f"{name}: the image tag must carry the Moodle version {version}"


def test_dockerfile_defaults_match_the_lock():
    pins, text = lock(), (DOCKER / "Dockerfile.alpine").read_text(encoding="utf-8")
    defaults = dict(re.findall(r"^ARG (\w+)=(\S+)$", text, re.M))
    assert defaults == {"PHP_IMAGE": pins["PHP_IMAGE"], "NGINX_IMAGE": pins["NGINX_IMAGE"]}


def test_lock_has_no_unused_entries():
    used = {"PHP_IMAGE", "NGINX_IMAGE", "MOODLE_TAG", "MOODLE_COMMIT", "MOODLE_REPO", "CUSTOMCERT_TAG", "CUSTOMCERT_COMMIT", "CUSTOMCERT_REPO",
            "POSTGRES_IMAGE", "MAILPIT_IMAGE"}
    assert set(lock()) == used
