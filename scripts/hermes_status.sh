#!/usr/bin/env bash
# Hermes Status — read-only snapshot of last-known state across all collectors,
# the most recent lifecycle run, git position, and pending session dirs.
# Does NOT run anything heavy — pure aggregation of what's already on disk.
# Always exits 0.

set -uo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO"

TS=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

# Helper: read a JSON field, fall back to "—"
jq_field() {
  local file=$1 field=$2
  if [[ -f "$file" ]]; then
    python3 -c "import json; print(json.load(open('$file')).get('$field', '—'))" 2>/dev/null || echo "—"
  else
    echo "—"
  fi
}

jq_age() {
  local file=$1
  if [[ -f "$file" ]]; then
    local mtime
    mtime=$(stat -c %Y "$file" 2>/dev/null || echo 0)
    local now
    now=$(date +%s)
    local diff=$((now - mtime))
    if   [[ $diff -lt 60     ]]; then echo "${diff}s ago"
    elif [[ $diff -lt 3600   ]]; then echo "$((diff/60))m ago"
    elif [[ $diff -lt 86400  ]]; then echo "$((diff/3600))h ago"
    else                              echo "$((diff/86400))d ago"
    fi
  else
    echo "—"
  fi
}

echo "# Hermes Status"
echo ""
echo "**Snapshot:** \`$TS\`"
echo ""

# ── Collectors ────────────────────────────────────────────────────────────────
echo "## Collectors"
echo ""
echo "| Collector | Overall | Last run | Headline |"
echo "|---|---|---|---|"

watchdog_overall=$(jq_field "data/hermes_findings.json" "overall")
watchdog_pass=$(jq_field "data/hermes_findings.json" "pass")
watchdog_fail=$(jq_field "data/hermes_findings.json" "fail")
watchdog_warn=$(jq_field "data/hermes_findings.json" "warn")
echo "| Watchdog | $watchdog_overall | $(jq_age data/hermes_findings.json) | ${watchdog_pass} PASS / ${watchdog_fail} FAIL / ${watchdog_warn} WARN |"

audit_overall=$(jq_field "data/agents/audit-sentinel/findings.json" "overall")
audit_critical=$(jq_field "data/agents/audit-sentinel/findings.json" "critical")
audit_high=$(jq_field "data/agents/audit-sentinel/findings.json" "high")
audit_medium=$(jq_field "data/agents/audit-sentinel/findings.json" "medium")
echo "| Audit Sentinel | $audit_overall | $(jq_age data/agents/audit-sentinel/findings.json) | ${audit_critical} CRITICAL / ${audit_high} HIGH / ${audit_medium} MEDIUM |"

update_overall=$(jq_field "data/agents/update-inspector/findings.json" "overall")
update_php_total=$(jq_field "data/agents/update-inspector/findings.json" "php_total")
update_php_major=$(jq_field "data/agents/update-inspector/findings.json" "php_major")
update_js_total=$(jq_field "data/agents/update-inspector/findings.json" "js_total")
update_js_major=$(jq_field "data/agents/update-inspector/findings.json" "js_major")
echo "| Update Inspector | $update_overall | $(jq_age data/agents/update-inspector/findings.json) | PHP ${update_php_total} (${update_php_major} major) / JS ${update_js_total} (${update_js_major} major) |"

system_overall=$(jq_field "data/agents/system-check/findings.json" "overall")
system_pass=$(jq_field "data/agents/system-check/findings.json" "pass")
system_warn=$(jq_field "data/agents/system-check/findings.json" "warn")
system_fail=$(jq_field "data/agents/system-check/findings.json" "fail")
echo "| System Check | $system_overall | $(jq_age data/agents/system-check/findings.json) | ${system_pass} PASS / ${system_warn} WARN / ${system_fail} FAIL |"

provider_overall=$(jq_field "data/agents/provider-health/findings.json" "overall")
provider_pass=$(jq_field "data/agents/provider-health/findings.json" "pass")
provider_warn=$(jq_field "data/agents/provider-health/findings.json" "warn")
provider_fail=$(jq_field "data/agents/provider-health/findings.json" "fail")
echo "| Provider Health | $provider_overall | $(jq_age data/agents/provider-health/findings.json) | ${provider_pass} PASS / ${provider_warn} WARN / ${provider_fail} FAIL |"

# ── Last lifecycle ────────────────────────────────────────────────────────────
echo ""
echo "## Last lifecycle"
echo ""
latest_lifecycle=$(ls -t docs/hermes/LIFECYCLE-*.md 2>/dev/null | head -1)
if [[ -n "$latest_lifecycle" ]]; then
  l_rel="${latest_lifecycle#$REPO/}"
  l_overall=$(grep -E "^overall:" "$latest_lifecycle" | head -1 | awk '{print $2}' || echo "—")
  l_completed=$(grep -E "^phases_completed:" "$latest_lifecycle" | head -1 | awk '{print $2}' || echo "—")
  l_date=$(grep -E "^date:" "$latest_lifecycle" | head -1 | awk '{print $2}' || echo "—")
  echo "- **Note:** \`$l_rel\`"
  echo "- **Verdict:** $l_overall"
  echo "- **Phases completed:** $l_completed / 8"
  echo "- **Run date:** $l_date"
  echo "- **Age:** $(jq_age "$latest_lifecycle")"
else
  echo "_No lifecycle runs yet. Invoke \`/hermes-lifecycle\` to seed the first one._"
fi

# ── Open session dirs ─────────────────────────────────────────────────────────
echo ""
echo "## Open lifecycle sessions"
echo ""
sess_count=$(ls -1d data/agents/lifecycle/*/ 2>/dev/null | wc -l)
if [[ "$sess_count" -gt 0 ]]; then
  echo "Total kept: **$sess_count** (rolling 10)"
  echo ""
  for d in $(ls -1dt data/agents/lifecycle/*/ 2>/dev/null | head -3); do
    sid=$(basename "$d")
    manifest_age=$(jq_age "${d}MANIFEST.md")
    # Try to read the last phase status from MANIFEST.md
    last_done=$(grep -cE "\| done \|" "${d}MANIFEST.md" 2>/dev/null || echo 0)
    echo "- \`$sid\` — $last_done/8 phases done, last touched $manifest_age"
  done
else
  echo "_No session dirs on disk._"
fi

# ── Git position ──────────────────────────────────────────────────────────────
echo ""
echo "## Git position"
echo ""
branch=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "—")
head_short=$(git rev-parse --short HEAD 2>/dev/null || echo "—")
uncommitted=$(git status --porcelain 2>/dev/null | wc -l)
echo "- **Branch:** \`$branch\` @ \`$head_short\`"
echo "- **Uncommitted changes:** $uncommitted file(s)"
if [[ "$uncommitted" -gt 0 ]]; then
  changed=$(git status --porcelain 2>/dev/null | head -5 | awk '{print $NF}' | tr '\n' ',' | sed 's/,$//')
  echo "- **Sample:** $changed"
fi

# Recent hermes commits
recent=$(git log --oneline --grep="^hermes-lifecycle:" -3 2>/dev/null)
if [[ -n "$recent" ]]; then
  echo ""
  echo "### Recent lifecycle commits"
  echo "$recent" | sed 's/^/- `/' | sed 's/$/`/'
fi

# ── PHPStan baseline ──────────────────────────────────────────────────────────
echo ""
echo "## Static analysis"
echo ""
if [[ -f phpstan-baseline.neon ]]; then
  baseline_lines=$(wc -l < phpstan-baseline.neon)
  baseline_tracked=$(git ls-files --error-unmatch phpstan-baseline.neon 2>/dev/null | wc -l)
  echo "- **PHPStan baseline:** $baseline_lines lines"
  if [[ "$baseline_tracked" == "1" ]]; then
    echo "- **Baseline gate:** ✅ committed (spans PRs)"
  else
    echo "- **Baseline gate:** ⚠️ uncommitted (gate active locally only)"
  fi
else
  echo "_PHPStan baseline not found._"
fi
