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
