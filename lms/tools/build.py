#!/usr/bin/env python3
"""Build a deterministic Moodle manifest + web-ready files from courses/<id>/course.yaml.

    python3 lms/tools/build.py --course concienciacion --cycle 2026 --start 2026-10-01

Output: build/<id>-<cycle>/manifest.json and build/<id>-<cycle>/files/**  (consumed by local_awarenesssync).
No Moodle access, no randomness, no timestamps: identical inputs give a byte-identical result. The manifest
contract is lms/tools/schema/manifest.schema.json; see AGENTS.md for the runbooks.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import html
import json
import os
import re
import shutil
import sys
import unicodedata
import urllib.parse
from pathlib import Path

import markdown
import yaml
from jsonschema import Draft202012Validator
from PIL import Image
from PIL import __version__ as PIL_VERSION

from extract_tests import NO_SHUFFLE_RE

SCHEMA_DIR = Path(__file__).resolve().parent / "schema"
COURSE_ID_RE = re.compile(r"^[a-z][a-z0-9_-]{2,40}$")
START_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
MANIFEST_VERSION = 1
IMG_TRANSFORM_VERSION = "v1"  # bump when the image pipeline changes on purpose (forces re-upload)
MAX_COPY_BYTES = 1_000_000  # small images already within max_px are copied untouched
Image.MAX_IMAGE_PIXELS = 200_000_000
IMAGE_EXTS = {".png", ".jpg", ".jpeg"}
MIME = {
    ".pdf": "application/pdf",
    ".pptx": "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    ".png": "image/png",
    ".jpg": "image/jpeg",
    ".jpeg": "image/jpeg",
}
MONTHS_ES = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"]
QUIZ_KEYS = ("pass_percent", "attempts", "grademethod", "shuffle_questions", "review")


class BuildError(Exception):
    pass


# ---------------------------------------------------------------- helpers
def nfc(s: str) -> str:
    return unicodedata.normalize("NFC", s)


def confine(p: Path, root: Path, what: str, rootname: str = "source_root") -> Path:
    """Resolve `p` and require it to live under `root`; symlinks are refused (copyfile would follow them out)."""
    real = os.path.realpath(p)
    base = os.path.realpath(root)
    if os.path.commonpath([real, base]) != base:
        raise BuildError(f"{what} escapes {rootname}: {p}")
    if os.path.islink(p):
        raise BuildError(f"{what} is a symlink: {p}")
    return Path(real)


def remove_child_tree(path: Path, parent: Path, ignore_errors: bool = False) -> None:
    """rmtree, but only ever for a direct child of `parent` (the build output folder): nothing else can be deleted through it."""
    if os.path.dirname(os.path.realpath(path)) != os.path.realpath(parent):
        raise BuildError(f"refusing to delete {path}: not a direct child of {parent}")
    shutil.rmtree(path, ignore_errors=ignore_errors)


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def canon(obj) -> str:
    return json.dumps(obj, sort_keys=True, ensure_ascii=False, separators=(",", ":"))


def digest(obj) -> str:
    return hashlib.sha256(canon(obj).encode("utf-8")).hexdigest()


def es_date(iso: str) -> str:
    d = dt.date.fromisoformat(iso)
    return f"{d.day} de {MONTHS_ES[d.month - 1]} de {d.year}"


def month_end(start: dt.date, n: int) -> dt.date:
    """Last day of calendar month `n` of a schedule whose month 1 is the month containing `start`."""
    idx = start.year * 12 + (start.month - 1) + n  # first month AFTER month n, as a running month index
    year, month0 = divmod(idx, 12)
    return dt.date(year, month0 + 1, 1) - dt.timedelta(days=1)


def module_due_dates(course: dict, start: dt.date) -> dict[str, dt.date]:
    """Per-module deadline for `completion.schedule: monthly`; empty for a legacy single-deadline course."""
    if "schedule" not in course["completion"]:
        stray = [m["key"] for m in course["modules"] if "due_month" in m]
        if stray:
            raise BuildError(f"due_month needs completion.schedule (set on {', '.join(stray)})")
        return {}
    return {m["key"]: month_end(start, m.get("due_month", pos)) for pos, m in enumerate(course["modules"], 1)}


def validator(name: str) -> Draft202012Validator:
    return Draft202012Validator(json.loads((SCHEMA_DIR / name).read_text(encoding="utf-8")))


def check_schema(instance, schema_name: str, what: str) -> None:
    errors = sorted(validator(schema_name).iter_errors(instance), key=lambda e: list(e.absolute_path))
    if errors:
        lines = [f"  - {'/'.join(str(p) for p in e.absolute_path) or '(root)'}: {e.message}" for e in errors[:15]]
        raise BuildError(f"{what} does not match {schema_name}:\n" + "\n".join(lines))


def load_yaml(path: Path, schema_name: str, what: str) -> dict:
    try:
        data = yaml.safe_load(path.read_text(encoding="utf-8"))
    except (OSError, yaml.YAMLError) as e:
        raise BuildError(f"cannot read {what} {path}: {e}") from e
    check_schema(data, schema_name, f"{what} {path.name}")
    return data


def render_text(md_text: str, variables: dict) -> str:
    def sub(m):
        name = m.group(1)
        if name not in variables:
            raise BuildError(f"unknown template variable {{{{{name}}}}} (known: {', '.join(sorted(variables))})")
        return html.escape(str(variables[name]), quote=False)

    return markdown.markdown(re.sub(r"\{\{\s*(\w+)\s*\}\}", sub, md_text), extensions=["extra"])


def stable_view(obj):
    """Item view used for content hashes: drops derived/volatile fields (built-file bytes, paths, the hash itself)."""
    if isinstance(obj, dict):
        if "source_sha256" in obj and "transform" in obj:
            return {k: obj[k] for k in ("source_sha256", "transform", "filename")}
        return {k: stable_view(v) for k, v in obj.items() if k != "content_hash"}
    if isinstance(obj, list):
        return [stable_view(v) for v in obj]
    return obj


DEADLINE_NOTE = "La fecha límite de este módulo aparece en tu Línea de tiempo y en el Calendario."
INITIAL_ONLY = ("review",)  # Item fields that are only the INITIAL value in Moodle (the administrator owns them afterwards).


def finalize(item: dict) -> dict:
    # Initial-only fields stay in the manifest (used when the activity is created) but must not make an existing one "changed".
    item["content_hash"] = digest(stable_view({k: v for k, v in item.items() if k not in INITIAL_ONLY}))
    return item


# ---------------------------------------------------------------- assets
class Assets:
    """Copies documents and web-optimises images into <build>/files/, returning manifest 'file' dicts."""

    def __init__(self, build_dir: Path, cache_dir: Path, max_px: int, quality: int, dry: bool, source_root: Path | None = None):
        self.build_dir, self.cache_dir, self.max_px, self.quality, self.dry = build_dir, cache_dir, max_px, quality, dry
        self.source_root = source_root
        self.count = 0
        self.bytes = 0
        self._seen: set[str] = set()

    def _check_src(self, src: Path) -> None:
        if self.source_root is not None:
            confine(src, self.source_root, "source file")

    def _dest(self, rel_dir: str, filename: str) -> Path:
        # Two sources ending up as the same output name would silently overwrite each other (and list one image twice).
        ident = f"{rel_dir}/{filename}".casefold()
        if ident in self._seen:
            raise BuildError(f"output file name collision in {rel_dir}: {filename}")
        self._seen.add(ident)
        files_root = (self.build_dir / "files").resolve()
        dest = self.build_dir / "files" / rel_dir / filename
        if files_root not in dest.resolve().parents:
            raise BuildError(f"refusing to write outside the build directory: {dest}")
        if not self.dry:
            dest.parent.mkdir(parents=True, exist_ok=True)
        return dest

    def _record(self, rel_dir: str, filename: str, dest: Path, src_sha: str, transform: str, size: int, out_sha: str) -> dict:
        self.count += 1
        self.bytes += size
        return {
            "path": f"files/{rel_dir}/{filename}",
            "filename": filename,
            "sha256": out_sha,
            "size": size,
            "mime": MIME.get(dest.suffix.lower(), "application/octet-stream"),
            "source_sha256": src_sha,
            "transform": transform,
        }

    def add_file(self, src: Path, rel_dir: str) -> dict:
        self._check_src(src)
        filename = nfc(src.name)
        dest = self._dest(rel_dir, filename)
        if self.dry:
            return self._record(rel_dir, filename, dest, "0" * 64, "copy", 0, "0" * 64)
        sha = sha256_file(src)
        shutil.copyfile(src, dest)
        return self._record(rel_dir, filename, dest, sha, "copy", src.stat().st_size, sha)

    def _image_plan(self, src: Path) -> tuple[bool, str]:
        """(copy untouched?, output file name). One rule for a real build and for --validate-only, so that both see the
        same output names: `make validate` must report the name collisions `make build` would fail on."""
        with Image.open(src) as im:
            small = max(im.size) <= self.max_px
        if small and src.stat().st_size <= MAX_COPY_BYTES:
            return True, nfc(src.name)
        return False, nfc(src.stem) + ".jpg"

    def add_image(self, src: Path, rel_dir: str) -> dict:
        self._check_src(src)
        copy, filename = self._image_plan(src)
        if self.dry:
            return self._record(rel_dir, filename, self._dest(rel_dir, filename), "0" * 64, "copy" if copy else "jpeg", 0, "0" * 64)
        sha = sha256_file(src)
        if copy:
            transform, data_path = "copy", src
        else:
            transform = f"jpeg:{self.max_px}:q{self.quality}:{IMG_TRANSFORM_VERSION}"
            # Pillow's version is part of the cache key: another encoder must not reuse (and mix) cached JPEG bytes.
            data_path = self.cache_dir / f"{sha}-{self.max_px}-q{self.quality}-{IMG_TRANSFORM_VERSION}-pil{PIL_VERSION}.jpg"
            if not data_path.exists():
                self._render(src, data_path)
        dest = self._dest(rel_dir, filename)
        shutil.copyfile(data_path, dest)
        return self._record(rel_dir, filename, dest, sha, transform, dest.stat().st_size, sha256_file(dest))

    def _render(self, src: Path, target: Path) -> None:
        self.cache_dir.mkdir(parents=True, exist_ok=True)
        with Image.open(src) as im:
            im.load()
            if im.mode in ("RGBA", "LA") or (im.mode == "P" and "transparency" in im.info):
                rgba = im.convert("RGBA")
                flat = Image.new("RGB", rgba.size, (255, 255, 255))
                flat.paste(rgba, mask=rgba.getchannel("A"))
                im = flat
            else:
                im = im.convert("RGB")
            im.thumbnail((self.max_px, self.max_px), Image.LANCZOS)
            tmp = target.with_suffix(".tmp")
            im.save(tmp, "JPEG", quality=self.quality, optimize=True)
        tmp.replace(target)


# ---------------------------------------------------------------- discovery
def images_in(folder: Path) -> list[Path]:
    return sorted((p for p in folder.iterdir() if p.suffix.lower() in IMAGE_EXTS), key=lambda p: nfc(p.name)) if folder.is_dir() else []


def discover_module(folder: Path, files_cfg: dict, key: str, source_root: Path) -> dict:
    """Find a module's files by convention, honouring per-module overrides from course.yaml.

    Every override is confined to source_root: an absolute path or `..` in course.yaml must not pull in arbitrary files."""
    if not folder.is_dir():
        raise BuildError(f"{key}: module folder not found: {folder}")

    def one(pattern_dir: str, pattern: str, override, what: str, required: bool):
        if override is not None:
            p = confine(folder / override, source_root, f"{key}: files.{what}")
            if not p.is_file():
                raise BuildError(f"{key}: override for {what} not found: {p}")
            return p
        base = folder / pattern_dir if pattern_dir else folder
        found = sorted(base.glob(pattern), key=lambda p: nfc(p.name)) if base.is_dir() else []
        if len(found) > 1:
            raise BuildError(f"{key}: {len(found)} candidates for {what} in {base}; set files.{what} in course.yaml")
        if not found and required:
            raise BuildError(f"{key}: no {what} found in {base}")
        return found[0] if found else None

    def opt(name: str):
        return files_cfg[name] if name in files_cfg else None

    def dir_of(name: str, default: str) -> Path | None:
        if name in files_cfg and files_cfg[name] is None:
            return None
        if files_cfg.get(name):
            # An explicit override that does not exist is a typo, not "no images": fail instead of silently dropping the page.
            d = confine(folder / files_cfg[name], source_root, f"{key}: files.{name}")
            if not d.is_dir():
                raise BuildError(f"{key}: override for {name} is not a folder: {d}")
            return d
        d = folder / default
        return d if d.is_dir() else None

    def disabled(name: str) -> bool:
        return name in files_cfg and files_cfg[name] is None

    cons, post = dir_of("consejos", "Consejos"), dir_of("posters", "Posters")
    return {
        "pdf": one("", "*.pdf", opt("pdf"), "pdf", True),
        "ficha": None if disabled("ficha") else one("Ficha", "*.pdf", opt("ficha"), "ficha", False),
        "consejos": images_in(cons) if cons else [],
        "posters": images_in(post) if post else [],
        "pptx": None if disabled("presentacion") else one("Presentacion", "*.pptx", opt("presentacion"), "presentacion", False),
    }


def load_questions(path: Path, module_key: str, source_root: Path, warnings: list[str]) -> tuple[list[dict], list[str]]:
    bank = load_yaml(path, "questions.schema.json", "question bank")
    if bank["module"] != module_key:
        raise BuildError(f"{path.name}: module is {bank['module']}, expected {module_key}")
    seen, active, retired = set(), [], []
    for q in bank["questions"]:
        if not q["key"].startswith(module_key + "-"):
            raise BuildError(f"{path.name}: question key {q['key']} does not start with {module_key}-")
        if q["key"] in seen:
            raise BuildError(f"{path.name}: duplicate question key {q['key']}")
        seen.add(q["key"])
        if q.get("retired"):
            retired.append(q["key"])
        else:
            if q.get("shuffle", True) and any(NO_SHUFFLE_RE.search(o) for o in q["options"].values()):
                raise BuildError(f"{path.name}: {q['key']} has position-dependent options («c», «todas las anteriores», …) "
                                 f"and must set shuffle: false")
            active.append({k: q[k] for k in ("key", "text", "options", "answer")} | {"shuffle": q.get("shuffle", True)})
    if not active:
        raise BuildError(f"{path.name}: no active questions")
    src = bank.get("source")
    if src:
        pdf = confine(source_root / src["file"], source_root, f"{module_key}: source.file")
        if not pdf.is_file():
            warnings.append(f"{module_key}: question source PDF missing: {src['file']}")
        elif sha256_file(pdf) != src["sha256"]:
            warnings.append(f"{module_key}: source PDF changed since extraction ({src['file']}); run `make extract FORCE=1` and review the diff")
    return active, retired


# ---------------------------------------------------------------- manifest
def gallery_html(files: list[dict], alt: str) -> str:
    rows = ['<div class="awareness-gallery">']
    for i, f in enumerate(files, 1):
        rows.append(f'<p><img src="@@PLUGINFILE@@/{urllib.parse.quote(f["filename"])}" alt="{html.escape(alt)} {i}" style="max-width:100%;height:auto"></p>')
    rows.append("</div>")
    return "\n".join(rows)


def build(repo_root: Path, course_id: str, cycle: int, start: str, out_root: Path, dry: bool = False,
          build_dir: Path | None = None, enrol_cohort: str | None = None) -> tuple[dict, list[str], Assets]:
    course_file = repo_root / "courses" / course_id / "course.yaml"
    if not course_file.is_file():
        raise BuildError(f"course not found: {course_file}")
    course = load_yaml(course_file, "course.schema.json", "course")
    if course["id"] != course_id:
        raise BuildError(f"course.yaml id is {course['id']!r} but folder is {course_id!r}")
    keys = [m["key"] for m in course["modules"]]
    if len(keys) != len(set(keys)):
        raise BuildError("duplicate module keys in course.yaml")

    repo_resolved = repo_root.resolve()
    course_dir = course_file.parent.resolve()
    source_root = confine(course_file.parent / course["source_root"], repo_resolved, "source_root", "the repository")
    start_d = dt.date.fromisoformat(start)
    module_due = module_due_dates(course, start_d)
    if module_due:
        due = max(module_due.values()).isoformat()  # the course ends with its last module
    else:
        due = (start_d + dt.timedelta(days=course["completion"]["due_days"])).isoformat()
    prefix = f"{course_id}:{cycle}"
    build_dir = build_dir or out_root / f"{course_id}-{cycle}"
    img = course.get("images", {})
    assets = Assets(build_dir, out_root / ".cache" / "img", img.get("max_px", 1600), img.get("jpeg_quality", 85), dry, source_root)
    warnings: list[str] = []
    sections, quiz_keys, retired_all = [], [], []
    require_view = course["completion"]["require_pdf_view"]

    def inside_root(p: Path, what: str) -> Path:
        return confine(p, source_root, what)

    def inside_course(p: Path, what: str) -> Path:
        return confine(p, course_dir, what, "the course folder")

    # -- welcome section
    w = course["welcome"]
    variables = {
        "course_title": course["title"], "cycle": cycle, "modules_count": len(course["modules"]),
        "pass_percent": course["quiz_defaults"].get("pass_percent", "?"), "start_date": es_date(start), "due_date": es_date(due),
    }
    welcome_md = inside_course(course_file.parent / w["text"], "welcome.text")
    if not welcome_md.is_file():
        raise BuildError(f"welcome text not found: {welcome_md}")
    items = []

    def welcome_files(name: str, found, what: str) -> list[Path]:
        # A folder named in course.yaml that is missing or empty is a typo or a kit change, not "nothing to show": fail like a
        # mistyped module override does, instead of silently dropping the page from the welcome section.
        folder = inside_root(source_root / w[name], f"welcome.{name}")
        if not folder.is_dir():
            raise BuildError(f"welcome.{name} is not a folder: {folder}")
        files = found(folder)
        if not files:
            raise BuildError(f"welcome.{name} has no {what}: {folder}")
        return files

    if w.get("posters"):
        files = [assets.add_image(p, "S00/carteles") for p in welcome_files("posters", images_in, "images")]
        items.append(finalize({"key": f"{prefix}:S00:carteles", "type": "page", "name": "Carteles de presentación",
                               "content_html": gallery_html(files, "Cartel de presentación"), "files": files}))
    if w.get("trypticos"):
        pdfs = welcome_files("trypticos", lambda folder: sorted(folder.glob("*.pdf"), key=lambda p: nfc(p.name)), "PDF files")
        items.append(finalize({"key": f"{prefix}:S00:tripticos", "type": "folder", "name": "Trípticos con los consejos principales",
                               "files": [assets.add_file(p, "S00/tripticos") for p in pdfs]}))
    welcome_src = welcome_md.read_text(encoding="utf-8")
    sections.append({"key": f"{prefix}:S00", "title": "Bienvenida", "visible": True,
                     "summary_html": render_text(welcome_src, variables), "items": items})
    # For content_version only: the same text with the per-cycle dates masked (dates are scheduling, not content).
    canonical_summaries = {f"{prefix}:S00": render_text(welcome_src, {**variables, "start_date": "<start>", "due_date": "<due>"})}

    # -- modules
    trainer_items = []
    for m in course["modules"]:
        k = m["key"]
        found = discover_module(inside_root(source_root / m["folder"], f"{k}.folder"), m.get("files", {}), k, source_root)
        active, retired = load_questions(inside_course(course_file.parent / m["questions"], f"{k}.questions"), k, source_root, warnings)
        retired_all += retired
        quiz_cfg = {**course["quiz_defaults"], **m.get("quiz", {})}
        missing = [q for q in QUIZ_KEYS if q not in quiz_cfg]
        if missing:
            raise BuildError(f"{k}: quiz settings missing {missing}; set them in quiz_defaults or the module")
        pdf_key = f"{prefix}:{k}:pdf"
        mod_items = [finalize({"key": pdf_key, "type": "resource", "name": "Documento explicativo",
                               "file": assets.add_file(found["pdf"], f"{k}/documento"),
                               "completion": "view" if require_view else "none"})]
        if found["ficha"]:
            mod_items.append(finalize({"key": f"{prefix}:{k}:ficha", "type": "resource", "name": "Ficha resumen",
                                       "file": assets.add_file(found["ficha"], f"{k}/ficha"), "completion": "none"}))
        for part, label, alt in (("consejos", "Consejos", "Consejo"), ("posters", "Carteles", "Cartel")):
            if found[part]:
                files = [assets.add_image(p, f"{k}/{part}") for p in found[part]]
                mod_items.append(finalize({"key": f"{prefix}:{k}:{part}", "type": "page", "name": label,
                                           "content_html": gallery_html(files, f"{alt}: {m['title']}"), "files": files}))
        quiz_key = f"{prefix}:{k}:quiz"
        quiz_keys.append(quiz_key)
        mod_items.append(finalize({"key": quiz_key, "type": "quiz", "name": "Test de evaluación", **{q: quiz_cfg[q] for q in QUIZ_KEYS},
                                   "requires": [pdf_key] if require_view else [], "questions": active}))
        summary_html = f"<p>Lee el documento explicativo y supera el test con al menos un {quiz_cfg['pass_percent']}&nbsp;% de aciertos.</p>"
        if k in module_due:
            summary_html += f"<p>{DEADLINE_NOTE}</p>"
        section = {"key": f"{prefix}:{k}", "title": f"{int(k[1:])}. {m['title']}", "visible": True, "summary_html": summary_html,
                   "items": mod_items}
        if k in module_due:
            # Initial default of the module deadline (applied to the quiz's "Expect completed on" when it is created). The
            # administrator owns it afterwards, so the text must not print a date: learners see the real one in Moodle.
            section["due_date"] = module_due[k].isoformat()
        sections.append(section)
        if course.get("trainer_material") and found["pptx"]:
            trainer_items.append(finalize({"key": f"{prefix}:{k}:pptx", "type": "resource", "name": f"Presentación del formador: {m['title']}",
                                           "file": assets.add_file(found["pptx"], f"{k}/formador"), "completion": "none"}))
    if trainer_items:
        sections.append({"key": f"{prefix}:TRAINER", "title": "Material del formador (solo docentes)", "visible": False,
                         "summary_html": "<p>Presentaciones editables con notas para el ponente. No visible para los alumnos.</p>",
                         "items": trainer_items})

    # -- certificate
    cert = course["certificate"]
    sections.append({"key": f"{prefix}:CERT", "title": "Certificado", "visible": True,
                     "summary_html": "<p>Al superar todos los tests podrás descargar tu certificado de finalización.</p>",
                     "items": [finalize({"key": f"{prefix}:CERT:certificate", "type": "certificate", "name": "Certificado de finalización",
                                         "template": cert["template"], "validity_months": cert["validity_months"], "requires": quiz_keys})]})

    summary = f"<p>{html.escape(course['title'])} {cycle}</p>" + (f"<p>{html.escape(course['credit'])}</p>" if course.get("credit") else "")
    course_block = {"id": course_id, "cycle": cycle, "idnumber": prefix, "shortname": f"{course['shortname_prefix']}-{cycle}",
                    "fullname": f"{course['title']} {cycle}", "category": course["category"], "lang": course["lang"],
                    "summary_html": summary, "start_date": start, "due_date": due}
    # content_version tracks learner-visible content only: dates and other per-cycle scheduling are deliberately excluded.
    content_version = "c" + digest({
        "title": course["title"], "summary": summary, "lang": course["lang"], "require_pdf_view": require_view,
        "sections": [{"key": s["key"], "title": s["title"], "visible": s["visible"],
                      "summary_html": canonical_summaries.get(s["key"], s["summary_html"]),
                      "items": [i["content_hash"] for i in s["items"]]} for s in sections],
        "retired": sorted(retired_all)})[:12]
    manifest = {
        "manifest_version": MANIFEST_VERSION, "content_version": content_version, "course": course_block,
        "completion": {"require_pdf_view": require_view, "required_item_keys": quiz_keys}
        | ({"schedule": course["completion"]["schedule"]["mode"]} if module_due else {"due_days": course["completion"]["due_days"]}),
        "enrol": {"cohort": enrol_cohort or course["enrol"]["cohort"]}, "sections": sections, "retired_question_keys": sorted(retired_all),
    }
    check_schema(manifest, "manifest.schema.json", "generated manifest")
    return manifest, warnings, assets


def write_manifest(manifest: dict, build_dir: Path) -> Path:
    build_dir.mkdir(parents=True, exist_ok=True)
    path = build_dir / "manifest.json"
    path.write_text(json.dumps(manifest, sort_keys=True, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    return path


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--repo-root", type=Path, default=Path("."))
    ap.add_argument("--course", required=True)
    ap.add_argument("--cycle", type=int, help="cycle year; defaults to the year of --start")
    ap.add_argument("--start", help="cycle start date YYYY-MM-DD (required for a real build)")
    ap.add_argument("--out", type=Path, default=Path("build"))
    ap.add_argument("--strict", action="store_true", help="treat warnings as errors")
    ap.add_argument("--validate-only", action="store_true", help="check YAML, files and cross-references without building assets")
    ap.add_argument("--enrol-cohort", help="enrol this cohort instead of course.yaml's (scratch copies for `make e2e`: never the real employees)")
    a = ap.parse_args(argv)
    if not COURSE_ID_RE.match(a.course):
        ap.error(f"--course must match {COURSE_ID_RE.pattern}")
    if a.start and not START_RE.match(a.start):
        ap.error("--start must be YYYY-MM-DD")
    if not a.start and not a.validate_only:
        ap.error("--start is required (or use --validate-only)")
    try:
        start = dt.date.fromisoformat(a.start or "2000-01-01").isoformat()
    except ValueError as e:
        ap.error(f"--start: {e}")
    cycle = a.cycle or int(start[:4])
    repo, out = a.repo_root.resolve(), a.out.resolve()
    build_dir = out / f"{a.course}-{cycle}"
    # Build next to the final directory and swap on success: a failed build must never destroy the last good one.
    tmp_dir = out / f".tmp-{a.course}-{cycle}"
    owns_tmp = not a.validate_only  # --validate-only writes nothing, so it must not clean up after (or under) a running build
    try:
        if owns_tmp and tmp_dir.exists():
            remove_child_tree(tmp_dir, out)  # leftover of an interrupted build
        manifest, warnings, assets = build(repo, a.course, cycle, start, out, dry=a.validate_only, build_dir=tmp_dir, enrol_cohort=a.enrol_cohort)
        for w in warnings:
            print(f"WARNING {w}", file=sys.stderr)
        if warnings and a.strict:
            raise BuildError(f"{len(warnings)} warning(s) with --strict")
        n_items = sum(len(s["items"]) for s in manifest["sections"])
        if a.validate_only:
            print(f"OK   validated {a.course}: {len(manifest['sections'])} sections, {n_items} items")
            return 0
        write_manifest(manifest, tmp_dir)
        if build_dir.exists():
            old = out / f".old-{a.course}-{cycle}"
            if old.exists():
                remove_child_tree(old, out)
            build_dir.rename(old)
            tmp_dir.rename(build_dir)
            remove_child_tree(old, out)
        else:
            tmp_dir.rename(build_dir)
        path = build_dir / "manifest.json"
    except (BuildError, ValueError, OSError, KeyError, Image.DecompressionBombError) as e:
        print(f"ERROR {e!r}" if isinstance(e, KeyError) else f"ERROR {e}", file=sys.stderr)
        return 1
    finally:
        if owns_tmp and tmp_dir.exists():
            remove_child_tree(tmp_dir, out, ignore_errors=True)
    print(f"OK   {path}\n     content_version={manifest['content_version']} sections={len(manifest['sections'])} items={n_items} "
          f"files={assets.count} size={assets.bytes / 1e6:.1f} MB")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
