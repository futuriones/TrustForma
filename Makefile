# Single entry point for TrustForma (a Futurion Solutions S.L. product). Run `make <target>` from the repo root on Linux, or
# `gmake <target>` on macOS (Apple ships GNU make 3.81; `brew install make` gives GNU make 4+ as `gmake`).
# See README.md ("Puesta en marcha") for the quick start.

ifneq (,$(filter 1.% 2.% 3.%,$(MAKE_VERSION)))
$(error GNU make 4 or newer is required (found $(MAKE_VERSION)). On macOS: brew install make, then run gmake)
endif

# bash with -e and pipefail: a failing command in the middle of a recipe line or of a pipeline fails the target
# (plain sh reports only the last command of a pipeline). Not -u: START/CYCLE and friends are legitimately unset.
SHELL       := bash
.SHELLFLAGS := -e -o pipefail -c
# A recipe that fails must not leave its half-made target file behind (lms/docker/.env).
.DELETE_ON_ERROR:

# One runtime: php-fpm + nginx on Alpine (Dockerfile.alpine). The file and project names keep "alpine" because the
# Docker volumes are named after the project (awareness-lms-alpine_*).
COMPOSE := docker compose -f lms/docker/compose.alpine.yaml --env-file lms/docker/.env
EXEC    := $(COMPOSE) exec -T -u www-data moodle

# A project virtualenv (.venv, gitignored; `make venv`) wins over the system Python, so the pinned packages never touch the OS.
PY      := $(if $(wildcard .venv/bin/python3),.venv/bin/python3,python3)
COURSE  ?= concienciacion
GLOB    ?= RecursosFormativos/*/Test_evaluacion/*.pdf

# Values reach recipes through the environment ("$$COURSE"), so the shell never re-parses them and they can be checked safely.
# Exception: COURSE/START/CYCLE in build/plan/deploy/close-cycle/report are pasted by make, which is safe only because CHECK_ARGS
# is their first line and restricts them to a plain id, date or year. Every variable a user may set is in this list.
export COURSE START CYCLE BACKUP ZIP EXTRA TO GLOB CONFIRM REBRAND

# sha256sum on Linux, shasum on macOS (both understand `-c`).
SHA256 := $(if $(shell command -v sha256sum 2>/dev/null),sha256sum,shasum -a 256)
# Any permission bit for group or other (portable form of `find -perm /077`, which is GNU only).
GROUP_OTHER_BITS := \( -perm -0010 -o -perm -0020 -o -perm -0040 -o -perm -0001 -o -perm -0002 -o -perm -0004 \)

.PHONY: help venv deps lock lint setup live import-kit up rebuild down logs shell install status extract test test-fast test-e2e validate build configure plan deploy e2e report close-cycle backup restore rotate-token mail-test

SYNC      := $(EXEC) php local/awarenesssync/cli/sync.php
CYC        = $(if $(CYCLE),$(CYCLE),$(word 1,$(subst -, ,$(START))))
MANIFEST   = /build/$(COURSE)-$(CYC)/manifest.json

# First line of every target that takes COURSE / START / CYCLE: reject anything that is not a plain id, date or year.
CHECK_ARGS = @printf '%s' "$$COURSE" | grep -Eq '^[a-z][a-z0-9_-]{2,40}$$' || { echo "invalid COURSE (a-z 0-9 _ -)"; exit 2; }; \
  test -z "$$START" || printf '%s' "$$START" | grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2}$$' || { echo "invalid START (YYYY-MM-DD)"; exit 2; }; \
  test -z "$$CYCLE" || printf '%s' "$$CYCLE" | grep -Eq '^[0-9]{4}$$' || { echo "invalid CYCLE (YYYY)"; exit 2; }

# EXTRA goes to sync.php: only its two documented flags, nothing else.
CHECK_EXTRA = @for w in $$EXTRA; do case "$$w" in --fail-on-changes|--allow-structure-change) ;; \
  *) echo "invalid EXTRA: only --fail-on-changes and --allow-structure-change are accepted"; exit 2;; esac; done

help:
	@grep -E '^[a-z0-9-]+:.*##' $(MAKEFILE_LIST) | sed 's/:.*##/ -/'

venv: ## Create/refresh .venv with exactly the packages (and file hashes) locked in lms/tools/requirements.txt
	python3 -m venv .venv
	.venv/bin/python3 -m pip install --quiet --require-virtualenv --require-hashes -r lms/tools/requirements.txt

deps: ## Check the installed Python packages match lms/tools/requirements.txt
	$(PY) lms/tools/check_deps.py

# requirements.in holds the packages we use directly; the lock adds everything they pull in, each with its sha256, so an
# install can neither pick another version of an indirect dependency nor accept a tampered file. pip-tools runs from a
# throw-away virtualenv: it is a tool for this one step, not a dependency of the project.
PIP_TOOLS := 7.6.1
lock: ## Regenerate lms/tools/requirements.txt from requirements.in (after changing a pin), then run `make venv`
	@tmp=$$(mktemp -d); trap 'rm -rf "$$tmp"' EXIT; \
	  python3 -m venv "$$tmp/v"; "$$tmp/v/bin/pip" install --quiet pip-tools==$(PIP_TOOLS); \
	  cd lms/tools && "$$tmp/v/bin/pip-compile" --generate-hashes --quiet --strip-extras --allow-unsafe --no-header \
	    --output-file requirements.txt requirements.in; \
	  { echo '# Written by `make lock` from requirements.in (pip-tools $(PIP_TOOLS)). Do not edit: change requirements.in and run `make lock`.'; \
	    cat requirements.txt; } > "$$tmp/lock.txt" && mv "$$tmp/lock.txt" requirements.txt
	@grep -cE '^[A-Za-z0-9_.-]+==' lms/tools/requirements.txt | sed 's/^/>> locked packages: /'

lint: deps ## Static checks: ruff on the Python tooling, `php -l` on the Moodle plugin (needs the stack for the PHP part)
	$(PY) -m ruff check lms/tools
	@$(EXEC) sh -c 'fail=0; for f in $$(find local/awarenesssync -name "*.php"); do out=$$(php -l "$$f" 2>&1) || { echo "$$out"; fail=1; }; done; \
	  [ $$fail -eq 0 ] && echo "php -l: all plugin files OK"; exit $$fail'

# Zip from INCIBE -> live system. `make setup` prepares the content (kit/ and the question banks are local, never committed);
# `make live` does the whole thing: stack, Moodle, site settings, build, plan (printed) and deploy.
setup: ## Prepare the content: venv, import the INCIBE zip from upload/ into kit/, extract the question banks, validate
	$(CHECK_ARGS)
	$(MAKE) venv
	$(MAKE) import-kit APPLY=1
	$(MAKE) extract
	$(MAKE) validate

live: ## Zip in upload/ to running course: setup, up, install, configure, build, plan, deploy. Needs START=YYYY-MM-DD [COURSE=id]
	$(CHECK_ARGS)
	@test -n "$(START)" || { echo "usage: make live START=YYYY-MM-DD [COURSE=id]  (the INCIBE zip must be in upload/)"; exit 2; }
	$(MAKE) setup
	$(MAKE) up
	$(MAKE) install
	$(MAKE) configure
	$(MAKE) build START=$(START)
	$(MAKE) plan START=$(START)
	$(MAKE) deploy START=$(START)

# Exit 3 = "dry run found changes": a normal result here, not a make failure.
import-kit: deps ## Dry-run the INCIBE zip in upload/ against kit/ (report only); APPLY=1 imports it (ZIP=upload/<f>.zip, FORCE=1 optional)
	$(CHECK_ARGS)
	@rc=0; $(PY) lms/tools/import_kit.py --course $(COURSE) $(if $(ZIP),--zip "$$ZIP") $(if $(APPLY),--apply) $(if $(FORCE),--force) || rc=$$?; \
	  if [ $$rc -eq 3 ]; then echo ">> changes found (dry run: nothing written). Re-run with APPLY=1 to import."; exit 0; fi; exit $$rc

# Written to a temporary name first: if openssl is missing, no half-made .env (still holding the example passwords) is left behind.
lms/docker/.env:
	@db=$$(openssl rand -hex 16); adm="Aa1-$$(openssl rand -hex 12)!"; umask 077; \
	  sed "s|^MOODLE_DB_PASSWORD=.*|MOODLE_DB_PASSWORD=$$db|; s|^MOODLE_ADMIN_PASSWORD=.*|MOODLE_ADMIN_PASSWORD='$$adm'|" lms/docker/.env.example > $@.tmp; \
	  mv $@.tmp $@
	@echo ">> Created lms/docker/.env with random passwords (admin login: MOODLE_ADMIN_USER / MOODLE_ADMIN_PASSWORD in that file)."

# On localhost the example passwords only get a warning. Anywhere else (MOODLE_WWWROOT is not localhost) the stack refuses to start
# with them, or with a .env / token file that other users can read. A real deployment must live on a filesystem that honours
# file modes (a shared or network drive that shows every file as 777 cannot protect them).
up: lms/docker/.env ## Build and start the stack (http://localhost:8080, Mailpit http://localhost:8025, port MAILPIT_PORT in .env; localhost only)
	@wwwroot=$$(sed -n "s/^MOODLE_WWWROOT=//p" lms/docker/.env | tr -d "\"'"); bad=""; \
	  if grep -Eq '^MOODLE_(DB|ADMIN)_PASSWORD=.?[Cc]hange-me' lms/docker/.env; then bad="$$bad\n  - lms/docker/.env still has the example passwords (the DB one must also be changed inside Postgres)"; fi; \
	  if [ -n "$$(find lms/docker/.env $(GROUP_OTHER_BITS))" ]; then bad="$$bad\n  - lms/docker/.env can be read by other users (needs mode 600)"; fi; \
	  if [ -n "$$(find lms/docker/secrets -type f ! -name .gitkeep $(GROUP_OTHER_BITS))" ]; then bad="$$bad\n  - files in lms/docker/secrets can be read by other users (need mode 600)"; fi; \
	  if [ -z "$$wwwroot" ] || printf '%s' "$$wwwroot" | grep -Eq '^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?/?$$'; then \
	    if printf '%b' "$$bad" | grep -q 'example passwords'; then echo ">> WARNING: lms/docker/.env still has the example passwords. Fine on localhost only."; fi; \
	  elif [ -n "$$bad" ]; then \
	    printf '>> REFUSING to start for %s:%b\n' "$$wwwroot" "$$bad"; exit 2; \
	  fi
	$(COMPOSE) up -d --build

# `make up` reuses cached image layers, so the `apk upgrade` inside the image does not run again: OS packages stay as they were
# on the day the layer was built. This rebuilds from nothing (fetches Moodle again, recompiles the PHP extensions: ~10 min).
rebuild: lms/docker/.env ## Rebuild the images from scratch to pick up OS security updates, then restart (data volumes are kept)
	$(COMPOSE) build --no-cache
	$(MAKE) up

down: ## Stop the stack (data volumes are kept)
	$(COMPOSE) down

logs: ## Follow the logs of Moodle (php-fpm), nginx and cron
	$(COMPOSE) logs -f moodle web cron

shell: ## Shell (sh) inside the Moodle container
	$(COMPOSE) exec -u www-data moodle sh

# cfg.php exits 0 when Moodle is installed and 1 when the database is empty (verified on 4.5.14).
IS_INSTALLED := $(EXEC) php admin/cli/cfg.php --name=release

status: ## Is the stack up and Moodle installed? Show release and plugin versions
	@if [ -z "$$($(COMPOSE) ps -q moodle 2>/dev/null)" ]; then echo "stack is not running: make up"; \
	elif $(IS_INSTALLED) >/dev/null 2>&1; then \
	  echo "installed: $$($(IS_INSTALLED) | tr -d '\r')"; \
	  echo "mod_customcert: $$($(EXEC) php admin/cli/cfg.php --component=mod_customcert --name=version | tr -d '\r')"; \
	  echo "local_awarenesssync: $$($(EXEC) php admin/cli/cfg.php --component=local_awarenesssync --name=version | tr -d '\r')"; \
	else echo "not installed: make install"; fi

extract: ## Bootstrap courses/$(COURSE)/questions/*.yaml from the test PDFs (skips existing; FORCE=1 overwrites)
	$(CHECK_ARGS)
	$(PY) lms/tools/extract_tests.py --repo-root kit --glob "$$GLOB" --out courses/$(COURSE)/questions \
	  --report build/extract_review.md $(if $(FORCE),--force)

test: deps ## Python tests incl. a full real-kit build twice (~1 min). No Moodle needed
	$(PY) -m pytest lms/tools/tests -q -p no:cacheprovider -m "not e2e"

test-fast: deps ## Python tests without the slow real-kit build (~10 s). No Moodle needed
	$(PY) -m pytest lms/tools/tests -q -p no:cacheprovider -m "not slow and not e2e"

test-e2e: deps ## Content-lifecycle acceptance test on the RUNNING Moodle (synthetic course, cleaned up afterwards)
	$(PY) -m pytest lms/tools/tests/test_e2e_lifecycle.py -q -p no:cacheprovider -m e2e

# Test learners take real quiz attempts, and Moodle keeps those after the users are deleted. On a real cycle they would make the
# sync treat the course as "in use" (blocked changes) and stay in its reports, so the journey runs on a throw-away copy instead:
# the real course content, deployed as cycle 9999 for the test cohort, starting today, deleted afterwards (also when it fails).
SCRATCH  := 9999
E2E_TOOL  = $(EXEC) php local/awarenesssync/cli/e2e_scenario.php --idnumber=$(COURSE):$(SCRATCH)

e2e: deps ## Learner-journey acceptance check on a scratch copy of the course (cycle 9999, deleted afterwards; temporary users). [COURSE=id]
	$(CHECK_ARGS)
	@$(E2E_TOOL) --do=delete-course >/dev/null
	$(PY) lms/tools/build.py --course $(COURSE) --start $$(date +%F) --cycle $(SCRATCH) --enrol-cohort e2e-test --strict
	@$(E2E_TOOL) --do=ensure-cohort >/dev/null
	@rc=0; { $(SYNC) --manifest=/build/$(COURSE)-$(SCRATCH)/manifest.json --apply >/dev/null \
	    && $(EXEC) php local/awarenesssync/cli/e2e_check.php --idnumber=$(COURSE):$(SCRATCH) --keep \
	    && echo "== Contract (the report with the test learners still in it)" \
	    && $(call REPORT_CALL,$(SCRATCH)) | $(PY) lms/tools/check_report.py --quiet; } || rc=$$?; \
	  $(E2E_TOOL) --do=cleanup >/dev/null || true; $(E2E_TOOL) --do=delete-course >/dev/null || true; \
	  rm -rf "build/$$COURSE-$(SCRATCH)"; \
	  echo ">> scratch course $(COURSE):$(SCRATCH) removed"; exit $$rc

validate: deps ## Check course.yaml, kit files and question banks (warnings are errors); builds nothing
	$(CHECK_ARGS)
	$(PY) lms/tools/build.py --course $(COURSE) --validate-only --strict

build: deps ## Build build/$(COURSE)-<cycle>/ from the kit. Needs START=YYYY-MM-DD; CYCLE defaults to its year
	$(CHECK_ARGS)
	@test -n "$(START)" || { echo "usage: make build START=YYYY-MM-DD [CYCLE=YYYY] [COURSE=id]"; exit 2; }
	$(PY) lms/tools/build.py --course $(COURSE) --start $(START) $(if $(CYCLE),--cycle $(CYCLE)) --strict

configure: ## Apply site settings (idempotent): completion, timezone, REST, cohort 'empleados', API token, branding (REBRAND=1 re-applies it)
	$(EXEC) php local/awarenesssync/cli/configure_site.php $(if $(REBRAND),--rebrand)

rotate-token: ## Revoke the GRC API token and issue a new one (then paste it into the portal: Workflows > W6 > Secrets)
	$(EXEC) php local/awarenesssync/cli/configure_site.php --rotate-token

mail-test: ## Send one test email through the outgoing mail set in lms/docker/.env. Needs TO=address
	@printf '%s' "$$TO" | grep -Eq '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+$$' || { echo "usage: make mail-test TO=address@example.com"; exit 2; }
	$(EXEC) php local/awarenesssync/cli/mail_test.php --to="$$TO"

plan: ## Show what `deploy` would change in Moodle (writes nothing). Needs START=YYYY-MM-DD or CYCLE=YYYY
	$(CHECK_ARGS)
	$(CHECK_EXTRA)
	@test -n "$(START)$(CYCLE)" || { echo "usage: make plan START=YYYY-MM-DD | CYCLE=YYYY [COURSE=id]"; exit 2; }
	$(SYNC) --manifest=$(MANIFEST) --plan $$EXTRA

deploy: ## Sync the built manifest into Moodle (run `make plan` first and read it)
	$(CHECK_ARGS)
	$(CHECK_EXTRA)
	@test -n "$(START)$(CYCLE)" || { echo "usage: make deploy START=YYYY-MM-DD | CYCLE=YYYY [COURSE=id]"; exit 2; }
	$(SYNC) --manifest=$(MANIFEST) --apply $$EXTRA

close-cycle: ## Freeze a finished cycle: deploys to it are refused from now on. Needs CYCLE=YYYY [COURSE=id]
	$(CHECK_ARGS)
	@test -n "$(START)$(CYCLE)" || { echo "usage: make close-cycle CYCLE=YYYY [COURSE=id]"; exit 2; }
	$(EXEC) php local/awarenesssync/cli/close_cycle.php --idnumber=$(COURSE):$(CYC)

# $(call REPORT_CALL,<cycle>): the compliance API, POST with the token on stdin: it never appears in the URL (web server logs) or
# in any process argument list. The token file is read through the container. (The portal's workflow W6 uses GET with the token
# in an Authorization header instead; `make e2e` checks that form.) Moodle answers HTTP 200 for errors too ({"exception": ...}):
# pipe into check_report.py, which turns that, and any departure from the contract
# (lms/tools/schema/compliance-report.v1.schema.json), into a failing exit code.
define REPORT_CALL
{ printf 'wstoken='; $(EXEC) sh -c 'tr -d "\n" < /secrets/grc_token'; \
  printf '&moodlewsrestformat=json&wsfunction=local_awarenesssync_get_compliance_report&course=%s&cycle=%s' "$$COURSE" "$(1)"; } \
 | curl -fsS -X POST --data-binary @- -H 'Content-Type: application/x-www-form-urlencoded' "http://localhost:8080/webservice/rest/server.php"
endef

report: deps ## Call the compliance API and check the answer against the contract. Needs START=YYYY-MM-DD or CYCLE=YYYY
	$(CHECK_ARGS)
	@test -n "$(START)$(CYCLE)" || { echo "usage: make report START=YYYY-MM-DD | CYCLE=YYYY [COURSE=id]"; exit 2; }
	@$(EXEC) test -s /secrets/grc_token || { echo "no token yet: run 'make configure'"; exit 2; }
	@$(call REPORT_CALL,$(CYC)) | $(PY) lms/tools/check_report.py

# Written to backups/.partial-<ts> and renamed at the end: a backup folder that exists is a complete one. Caches, temp files,
# sessions and the file trash are left out (Moodle rebuilds them; restoring them next to an older database only causes trouble).
# META records what the dump belongs to. The site is NOT put in maintenance mode: take the backup when nobody is deploying.
BACKUP_SKIP := cache localcache temp sessions trashdir
backup: ## Dump the database and moodledata to backups/<timestamp>/ (this IS the audit evidence: keep it)
	@umask 077; ts=$$(date +%Y%m%d-%H%M%S); d=backups/$$ts; tmp=backups/.partial-$$ts; mkdir -p "$$tmp"; \
	trap 'rm -rf "$$tmp"' EXIT; \
	$(COMPOSE) exec -T db sh -c 'pg_dump -U "$$POSTGRES_USER" -Fc "$$POSTGRES_DB"' > "$$tmp/moodle.pgdump"; \
	$(COMPOSE) exec -T -u www-data moodle tar -C /var/www $(foreach x,$(BACKUP_SKIP),--exclude=moodledata/$(x)) -czf - moodledata > "$$tmp/moodledata.tgz"; \
	{ echo "created=$$(date -u +%Y-%m-%dT%H:%M:%SZ)"; echo "git_commit=$$(git rev-parse HEAD 2>/dev/null || echo unknown)"; \
	  echo "moodle_release=$$($(IS_INSTALLED) | tr -d '\r')"; \
	  echo "plugin_version=$$($(EXEC) php admin/cli/cfg.php --component=local_awarenesssync --name=version | tr -d '\r')"; \
	  echo "excluded_from_moodledata=$(BACKUP_SKIP)"; } > "$$tmp/META"; \
	( cd "$$tmp" && $(SHA256) moodle.pgdump moodledata.tgz META > SHA256SUMS ); \
	mv "$$tmp" "$$d"; trap - EXIT; \
	echo ">> backup written to $$d"; cat "$$d/META"; ls -lh "$$d"

restore: ## Restore BACKUP=backups/<timestamp> CONFIRM=yes into the running stack (DESTROYS current data)
	@test -d "$$BACKUP" || { echo "usage: make restore BACKUP=backups/<timestamp> CONFIRM=yes"; exit 2; }
	@test "$$CONFIRM" = "yes" || { echo "restore DESTROYS the current database and moodledata. Take 'make backup' first, then re-run with CONFIRM=yes"; exit 2; }
	@( cd "$$BACKUP" && $(SHA256) -c SHA256SUMS ) || { echo "backup is corrupt or incomplete"; exit 1; }
	@test ! -f "$$BACKUP/META" || { echo ">> restoring a backup of:"; cat "$$BACKUP/META"; }
	$(COMPOSE) stop moodle cron
	@# One transaction: if pg_restore fails the database is left exactly as it was (Moodle stays stopped: fix, then `make up`).
	$(COMPOSE) exec -T db sh -c 'pg_restore -U "$$POSTGRES_USER" -d "$$POSTGRES_DB" --clean --if-exists --no-owner --single-transaction --exit-on-error' < "$$BACKUP/moodle.pgdump"
	@# moodledata is NOT transactional: if this step fails, the database is already the restored one. Re-run the restore.
	$(COMPOSE) run --rm -T --no-deps moodle sh -c 'find /var/www/moodledata -mindepth 1 -delete && tar -C /var/www -xzf -' < "$$BACKUP/moodledata.tgz"
	$(COMPOSE) start moodle cron
	@echo ">> restored $$BACKUP. Run 'make status' and 'make plan …' to confirm."

# The admin password travels as an environment variable of the exec (-e VAR without a value copies it from this shell), so it is
# not an argument of `docker exec` on the host. Inside the container it is an argument of install_database.php for the few seconds
# the installation runs (the script takes it no other way): visible to whoever can list that container's processes at that moment.
install: ## Install Moodle (idempotent) and upgrade plugins
	@if $(IS_INSTALLED) >/dev/null 2>&1; then \
	  echo ">> Already installed, running upgrade"; \
	  $(EXEC) php admin/cli/upgrade.php --non-interactive; \
	else \
	  set -a; . lms/docker/.env; set +a; \
	  $(COMPOSE) exec -T -u www-data -e MOODLE_ADMIN_PASSWORD moodle sh -c \
	    'php admin/cli/install_database.php --agree-license --lang=es --adminuser="$$1" --adminpass="$$MOODLE_ADMIN_PASSWORD" --adminemail="$$2" --fullname="$$3" --shortname="$$4"' \
	    _ "$$MOODLE_ADMIN_USER" "$$MOODLE_ADMIN_EMAIL" "$$MOODLE_SITE_FULLNAME" "$$MOODLE_SITE_SHORTNAME"; \
	fi
