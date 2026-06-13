#!/usr/bin/env bash
# Hermes CI runner — automation_dashboard (Laravel 12 + Inertia/Vue 3 + Vite).
# Usage: ./scripts/hermes.sh [--fast]
#   --fast : skip pnpm install + vite build
#
# Writes:
#   data/hermes_findings.json  — machine-readable status
#   data/logs/*.log            — per-step output
#   stdout (markdown)          — human-readable summary at end of run
#
# Exit codes: 0 = PASS or WARN, 1 = FAIL
set -uo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
DATA="$REPO/data"
FINDINGS="$DATA/hermes_findings.json"
LOG_DIR="$DATA/logs"
LOG="$LOG_DIR/hermes_session.log"
FAST=0

for arg in "$@"; do
  [[ "$arg" == "--fast" ]] && FAST=1
done

mkdir -p "$LOG_DIR"
TS=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

pass=0; fail=0; warn=0
declare -a findings=()

log() { echo "[hermes] $*" | tee -a "$LOG"; }
record() {
  local status=$1 check=$2 detail=$3
  # strip newlines/quotes so detail stays JSON-safe
  detail=$(printf '%s' "$detail" | tr '\n' ' ' | sed 's/"/\\"/g')
  findings+=("{\"check\":\"$check\",\"status\":\"$status\",\"detail\":\"$detail\",\"ts\":\"$TS\"}")
  case "$status" in
    PASS) pass=$((pass + 1)); log "PASS  $check" ;;
    FAIL) fail=$((fail + 1)); log "FAIL  $check — $detail" ;;
    WARN) warn=$((warn + 1)); log "WARN  $check — $detail" ;;
  esac
}

cd "$REPO"

# ── 1. Vendor present ──────────────────────────────────────────────────────────
log "=== VENDOR ==="
if [[ -d vendor && -x vendor/bin/phpunit ]]; then
  record PASS vendor "composer deps present"
else
  record FAIL vendor "vendor/ missing or incomplete — run: composer install"
fi

# ── 2. Pint (style) ────────────────────────────────────────────────────────────
log "=== PINT ==="
if [[ -x vendor/bin/pint ]]; then
  if vendor/bin/pint --test > "$LOG_DIR/pint.log" 2>&1; then
    record PASS pint "no style issues"
  else
    record WARN pint "style issues — see data/logs/pint.log"
  fi
else
  record WARN pint "vendor/bin/pint not found"
fi

# ── 2b. PHPStan + shipmonk dead-code (baseline-tracked) ───────────────────────
log "=== PHPSTAN ==="
if [[ -x vendor/bin/phpstan ]]; then
  if vendor/bin/phpstan analyse --no-progress --memory-limit=2G > "$LOG_DIR/phpstan.log" 2>&1; then
    record PASS phpstan "no new issues beyond baseline"
  else
    record FAIL phpstan "new issues — see data/logs/phpstan.log"
  fi
else
  record WARN phpstan "vendor/bin/phpstan not found"
fi

# ── 3. PHPUnit / Laravel tests ─────────────────────────────────────────────────
log "=== TESTS ==="
if [[ -x vendor/bin/phpunit ]]; then
  if php artisan test --without-tty > "$LOG_DIR/test.log" 2>&1; then
    record PASS tests "all green"
  else
    record FAIL tests "test failures — see data/logs/test.log"
  fi
else
  record WARN tests "phpunit not available"
fi

# ── 4. Config + route sanity ───────────────────────────────────────────────────
log "=== CONFIG ==="
if php artisan config:cache > "$LOG_DIR/config.log" 2>&1; then
  record PASS config "config:cache succeeded"
else
  record FAIL config "config:cache failed — see data/logs/config.log"
fi
php artisan config:clear > /dev/null 2>&1 || true

if php artisan route:list -q > "$LOG_DIR/routes.log" 2>&1; then
  record PASS routes "route registration valid"
else
  record FAIL routes "route:list failed — see data/logs/routes.log"
fi

# ── 5. Migrations status ───────────────────────────────────────────────────────
log "=== MIGRATIONS ==="
if php artisan migrate:status > "$LOG_DIR/migrate.log" 2>&1; then
  pending=$(grep -ciE "pending" "$LOG_DIR/migrate.log" || true)
  if [[ "${pending:-0}" -gt 0 ]]; then
    record WARN migrations "$pending pending migration(s) — see data/logs/migrate.log"
  else
    record PASS migrations "all applied"
  fi
else
  record WARN migrations "migrate:status failed (DB may be unavailable)"
fi

# ── 5b. Composer security audit (fast, no network needed) ─────────────────────
log "=== COMPOSER AUDIT ==="
if composer audit --no-dev --format=plain > "$LOG_DIR/composer-audit.log" 2>&1; then
  record PASS composer-audit "no known CVEs in production deps"
else
  record WARN composer-audit "advisories present — see data/logs/composer-audit.log"
fi

# ── 5c. Composer manifest sanity (mirrors the CI quality job) ──────────────────
log "=== COMPOSER VALIDATE ==="
if composer validate --strict --no-check-all > "$LOG_DIR/composer-validate.log" 2>&1; then
  record PASS composer-validate "composer.json valid"
else
  record FAIL composer-validate "composer.json invalid — see data/logs/composer-validate.log"
fi

# ── 6. Frontend build + pnpm audit (skipped in --fast mode) ───────────────────
if [[ $FAST -eq 0 ]]; then
  log "=== FRONTEND BUILD ==="
  if [[ -f pnpm-lock.yaml ]]; then
    if pnpm install --frozen-lockfile > "$LOG_DIR/pnpm-install.log" 2>&1; then
      record PASS pnpm-install "deps installed"
      if pnpm run build > "$LOG_DIR/pnpm-build.log" 2>&1; then
        record PASS frontend-build "vite build succeeded"
      else
        record FAIL frontend-build "vite build failed — see data/logs/pnpm-build.log"
      fi
    else
      record FAIL pnpm-install "see data/logs/pnpm-install.log"
    fi
  else
    record WARN frontend-build "no pnpm-lock.yaml"
  fi

  log "=== PNPM AUDIT ==="
  if command -v pnpm >/dev/null 2>&1; then
    if pnpm audit --prod > "$LOG_DIR/pnpm-audit.log" 2>&1; then
      record PASS pnpm-audit "no known CVEs in production deps"
    else
      record WARN pnpm-audit "advisories present — see data/logs/pnpm-audit.log"
    fi
  else
    record WARN pnpm-audit "pnpm not on PATH"
  fi
fi

# ── 6c. Frontend dead code (knip) ──────────────────────────────────────────────
# Mirrors the PHP side: phpstan + shipmonk/dead-code-detector gate dead PHP;
# knip gates dead Vue/JS (unused files + exports). Runs in fast mode too — it's
# cheap and only needs node_modules present.
log "=== KNIP (frontend dead code) ==="
if [[ -x node_modules/.bin/knip ]]; then
  if node_modules/.bin/knip --include files,exports > "$LOG_DIR/knip.log" 2>&1; then
    record PASS knip "no unused frontend files/exports"
  else
    record FAIL knip "unused frontend code — see data/logs/knip.log"
  fi
else
  record WARN knip "knip not installed — run pnpm install"
fi

# ── 6d. Doc coverage ───────────────────────────────────────────────────────────
# Every app/ subsystem (a dir with PHP) must be registered in docs/hermes/manifest.json
# — pointing at a doc or explicitly waived. New undocumented code fails here.
log "=== DOC COVERAGE ==="
if command -v python3 >/dev/null 2>&1; then
  if python3 scripts/doc_coverage.py > "$LOG_DIR/doc-coverage.log" 2>&1; then
    record PASS doc-coverage "$(tail -1 "$LOG_DIR/doc-coverage.log")"
  else
    record FAIL doc-coverage "undocumented subsystem(s) — see data/logs/doc-coverage.log"
  fi
else
  record WARN doc-coverage "python3 not on PATH"
fi

# ── 6e. Tree checks (granular, node-scoped) ───────────────────────────────────
# Runs the manifest's per-node checks (margin-invariant, llm-contract, security,
# route-smoke, schedule, snapshots) as first-class findings, so a failure
# localizes to the exact subsystem in the findings graph instead of only
# tripping the broad `tests` gate. ~3s; the same runner does scoped dev runs
# (scripts/hermes_tree.py --domain <d> / --node <n>).
log "=== TREE CHECKS ==="
if command -v python3 >/dev/null 2>&1 && [[ -f docs/hermes/manifest.json ]]; then
  while IFS=$'\t' read -r tstatus tcheck tdetail; do
    [[ -z "$tcheck" ]] && continue
    record "$tstatus" "$tcheck" "$tdetail"
  done < <(python3 scripts/hermes_tree.py --emit 2>>"$LOG")
else
  record WARN tree-checks "python3 or manifest missing"
fi

# ── 7. Write findings JSON ─────────────────────────────────────────────────────
overall="PASS"
[[ $fail -gt 0 ]] && overall="FAIL"
[[ $fail -eq 0 && $warn -gt 0 ]] && overall="WARN"

joined=$(IFS=,; echo "${findings[*]:-}")
cat > "$FINDINGS" <<EOF
{
  "ts": "$TS",
  "overall": "$overall",
  "pass": $pass,
  "fail": $fail,
  "warn": $warn,
  "fast": $FAST,
  "findings": [$joined]
}
EOF

log "=== SUMMARY: $overall ($pass PASS / $fail FAIL / $warn WARN) ==="
echo "$TS  $overall  $pass/$fail/$warn  fast=$FAST" >> "$LOG"

# ── 7b. Enrich findings with manifest context (the trunk) ─────────────────────
# Attaches each finding to the manifest nodes its check covers + their edges
# (blast radius) + doc refs, and rolls status up per node/domain. Pure
# annotation — never changes the verdict.
if command -v python3 >/dev/null 2>&1 && [[ -f docs/hermes/manifest.json ]]; then
  log "=== FINDINGS GRAPH ==="
  python3 scripts/hermes_findings.py 2>&1 | tee -a "$LOG" || true
fi

# ── 8. Markdown summary (human-readable, printed to stdout) ───────────────────
echo ""
echo "# Hermes — automation_dashboard"
echo ""
echo "**Overall: $overall** · $pass PASS / $fail FAIL / $warn WARN · \`$TS\` · fast=$FAST"
echo ""
python3 - "$FINDINGS" <<'PYEOF' || true
import json, sys
d = json.load(open(sys.argv[1]))
icon = {"PASS":"✅","WARN":"⚠️","FAIL":"❌"}
for f in d["findings"]:
    print(f"- {icon.get(f['status'],'·')} `{f['check']}` — {f['detail']}")
PYEOF

[[ "$overall" == "FAIL" ]] && exit 1 || exit 0
