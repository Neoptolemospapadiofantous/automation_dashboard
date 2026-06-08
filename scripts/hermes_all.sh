#!/usr/bin/env bash
# Hermes All — runs watchdog + audit + update + system in sequence.
# Does NOT fail-fast — all 4 collectors always run, aggregate verdict emitted last.
# Exit code = worst-of-four (0 PASS/WARN, 1 FAIL).

set -uo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO"

worst="PASS"
fail=0

bump() {
  local v=$1
  case "$v" in
    FAIL) worst="FAIL"; fail=$((fail+1)) ;;
    WARN) [[ "$worst" == "PASS" ]] && worst="WARN" ;;
  esac
}

run() {
  local name=$1 cmd=$2
  echo ""
  echo "=== $name ==="
  $cmd 2>&1 | tail -n 15
  local rc=$?
  if [[ $rc -ne 0 ]]; then
    bump "FAIL"
  fi
}

run "Watchdog (hermes-fast)"     "bash scripts/hermes.sh --fast"
run "Audit Sentinel"             "bash scripts/agents/audit_sentinel.sh"
run "Update Inspector"           "bash scripts/agents/update_inspector.sh"
run "System Check"               "bash scripts/agents/system_check.sh"

# Read each collector's overall and aggregate
for f in data/hermes_findings.json \
         data/agents/audit-sentinel/findings.json \
         data/agents/update-inspector/findings.json \
         data/agents/system-check/findings.json; do
  if [[ -f "$f" ]]; then
    overall=$(python3 -c "import json; print(json.load(open('$f'))['overall'])" 2>/dev/null || echo "UNKNOWN")
    bump "$overall"
  fi
done

echo ""
echo "════════════════════════════════════════════════════════════════"
echo "  HERMES ALL — aggregate: $worst"
echo "════════════════════════════════════════════════════════════════"

[[ "$worst" == "FAIL" ]] && exit 1 || exit 0
