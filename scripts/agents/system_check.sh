#!/usr/bin/env bash
# System Check — runtime health: disk, log size, queue depth, DB + Typesense ping.
# Writes data/agents/system-check/findings.json + last_run.{json,log}
# No LLM. Free regardless of Anthropic billing rules.

set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="$REPO/data/agents/system-check"
TS=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
mkdir -p "$OUT"

declare -a findings=()
pass=0; warn=0; fail=0

update_status() {
  python3 "$REPO/scripts/agents/agent_status.py" \
    "$REPO/data/agents" "system-check" "$1" "$TS" "$2" 2>/dev/null || true
}

record() {
  local status=$1 check=$2 detail=$3
  detail=$(printf '%s' "$detail" | tr '\n' ' ' | sed 's/"/\\"/g')
  findings+=("{\"check\":\"$check\",\"status\":\"$status\",\"detail\":\"$detail\"}")
  case "$status" in
    PASS) pass=$((pass + 1)) ;;
    WARN) warn=$((warn + 1)) ;;
    FAIL) fail=$((fail + 1)) ;;
  esac
}

update_status "running" "started"
cd "$REPO"

# ── 1. Disk free where storage/ lives ─────────────────────────────────────────
disk_pct=$(df -P storage/ 2>/dev/null | tail -1 | awk '{print $5}' | tr -d '%')
disk_avail=$(df -Ph storage/ 2>/dev/null | tail -1 | awk '{print $4}')
if [[ -n "$disk_pct" ]]; then
  if [[ "$disk_pct" -ge 90 ]]; then
    record FAIL disk "${disk_pct}% used, only $disk_avail free on storage/ partition"
  elif [[ "$disk_pct" -ge 75 ]]; then
    record WARN disk "${disk_pct}% used, $disk_avail free on storage/ partition"
  else
    record PASS disk "${disk_pct}% used, $disk_avail free"
  fi
fi

# ── 2. Laravel log size ────────────────────────────────────────────────────────
if [[ -f storage/logs/laravel.log ]]; then
  log_bytes=$(stat -c %s storage/logs/laravel.log 2>/dev/null || echo 0)
  log_mb=$((log_bytes / 1024 / 1024))
  if [[ "$log_mb" -ge 500 ]]; then
    record WARN laravel-log "${log_mb}MB — rotation may be needed"
  else
    record PASS laravel-log "${log_mb}MB"
  fi

  # Recent ERROR/CRITICAL count (last 1000 lines)
  recent_errors=$(tail -n 1000 storage/logs/laravel.log 2>/dev/null | grep -cE "\.(ERROR|CRITICAL):" || true)
  if [[ "${recent_errors:-0}" -ge 50 ]]; then
    record WARN log-errors "${recent_errors} ERROR/CRITICAL lines in the last 1000 log lines"
  elif [[ "${recent_errors:-0}" -gt 0 ]]; then
    record PASS log-errors "${recent_errors} ERROR/CRITICAL lines in the last 1000 (within tolerance)"
  fi
else
  record PASS laravel-log "no log file"
fi

# ── 3. DB connection + size ────────────────────────────────────────────────────
if php artisan db:show --json > "$OUT/db.log" 2>&1; then
  record PASS db-connection "reachable"
else
  record FAIL db-connection "php artisan db:show failed — see data/agents/system-check/db.log"
fi

# ── 4. Queue depth ─────────────────────────────────────────────────────────────
if php artisan queue:size 2>"$OUT/queue.err" > "$OUT/queue.log"; then
  queue_size=$(tail -1 "$OUT/queue.log" | grep -oE '[0-9]+' | head -1)
  if [[ -n "$queue_size" ]]; then
    if [[ "$queue_size" -ge 1000 ]]; then
      record FAIL queue "${queue_size} jobs pending — backlog"
    elif [[ "$queue_size" -ge 100 ]]; then
      record WARN queue "${queue_size} jobs pending"
    else
      record PASS queue "${queue_size} jobs pending"
    fi
  fi
else
  record WARN queue "queue:size failed (driver may not support it)"
fi

# ── 5. Failed jobs ─────────────────────────────────────────────────────────────
failed_jobs=$(php artisan queue:failed 2>/dev/null | grep -cE "^\| [a-f0-9-]{36}" || true)
if [[ "${failed_jobs:-0}" -ge 50 ]]; then
  record WARN failed-jobs "${failed_jobs} failed jobs accumulated"
elif [[ "${failed_jobs:-0}" -gt 0 ]]; then
  record PASS failed-jobs "${failed_jobs} failed job(s) (within tolerance)"
else
  record PASS failed-jobs "none"
fi

# ── 6. Typesense reachability (if configured) ─────────────────────────────────
ts_host=$(grep -E "^TYPESENSE_HOST=" .env 2>/dev/null | cut -d= -f2 | tr -d '"' | tr -d "'")
ts_port=$(grep -E "^TYPESENSE_PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '"' | tr -d "'")
ts_port=${ts_port:-8108}
if [[ -n "$ts_host" ]]; then
  if curl -fsS --max-time 3 "http://${ts_host}:${ts_port}/health" > "$OUT/typesense.log" 2>&1; then
    record PASS typesense "${ts_host}:${ts_port} reachable"
  else
    record WARN typesense "${ts_host}:${ts_port} unreachable (see data/agents/system-check/typesense.log)"
  fi
fi

# ── 7. Scheduler heartbeat (last php artisan schedule:run) ────────────────────
sched_lock=$(ls -t storage/framework/cache/data/*/*/laravel-schedule-* 2>/dev/null | head -1)
if [[ -n "$sched_lock" ]]; then
  sched_age=$(( $(date +%s) - $(stat -c %Y "$sched_lock") ))
  if [[ "$sched_age" -gt 600 ]]; then
    record WARN scheduler "last schedule:run was $((sched_age / 60))m ago — cron may not be running"
  else
    record PASS scheduler "ran ${sched_age}s ago"
  fi
else
  record PASS scheduler "no recent scheduler trace (may not be configured)"
fi

# ── Write findings + summary ───────────────────────────────────────────────────
overall="PASS"
[[ $fail -gt 0 ]] && overall="FAIL"
[[ $fail -eq 0 && $warn -gt 0 ]] && overall="WARN"

joined=$(IFS=,; echo "${findings[*]:-}")
cat > "$OUT/findings.json" <<EOF
{
  "ts": "$TS",
  "overall": "$overall",
  "pass": $pass,
  "warn": $warn,
  "fail": $fail,
  "checks": [$joined]
}
EOF

{
  echo "# System Check — $overall"
  echo ""
  echo "**$TS** · $pass PASS / $warn WARN / $fail FAIL"
  echo ""
  python3 - "$OUT/findings.json" <<'PYEOF'
import json, sys
d = json.load(open(sys.argv[1]))
icon = {"PASS":"✅","WARN":"⚠️","FAIL":"❌"}
for c in d["checks"]:
    print(f"- {icon.get(c['status'],'·')} `{c['check']}` — {c['detail']}")
PYEOF
} > "$OUT/summary.md"

cat "$OUT/summary.md"
update_status "idle" "$overall"
[[ "$overall" == "FAIL" ]] && exit 1 || exit 0
