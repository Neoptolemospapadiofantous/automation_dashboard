#!/usr/bin/env bash
# Audit Sentinel — security + risk surface scan.
# Writes data/agents/audit-sentinel/findings.json + last_run.{json,log}
# No LLM. Free regardless of Anthropic billing rules.

set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="$REPO/data/agents/audit-sentinel"
TS=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
mkdir -p "$OUT"

declare -a findings=()
critical=0; high=0; medium=0; low=0

update_status() {
  python3 "$REPO/scripts/agents/agent_status.py" \
    "$REPO/data/agents" "audit-sentinel" "$1" "$TS" "$2" 2>/dev/null || true
}

record() {
  local severity=$1 check=$2 detail=$3
  detail=$(printf '%s' "$detail" | tr '\n' ' ' | sed 's/"/\\"/g')
  findings+=("{\"severity\":\"$severity\",\"check\":\"$check\",\"detail\":\"$detail\"}")
  case "$severity" in
    CRITICAL) critical=$((critical + 1)) ;;
    HIGH)     high=$((high + 1)) ;;
    MEDIUM)   medium=$((medium + 1)) ;;
    LOW)      low=$((low + 1)) ;;
  esac
}

update_status "running" "started"
cd "$REPO"

# ── 1. PHP dep CVEs (composer audit) ───────────────────────────────────────────
if composer audit --no-dev --format=plain > "$OUT/composer_audit.log" 2>&1; then
  : # no advisories
else
  n=$(grep -cE "^Package: " "$OUT/composer_audit.log" 2>/dev/null || echo 0)
  record HIGH "composer-cves" "$n production advisory(ies) — see data/agents/audit-sentinel/composer_audit.log"
fi

# ── 2. JS dep CVEs (pnpm audit) ────────────────────────────────────────────────
if command -v pnpm >/dev/null 2>&1; then
  if pnpm audit --prod > "$OUT/pnpm_audit.log" 2>&1; then
    : # clean
  else
    n=$(grep -ciE "vulnerab" "$OUT/pnpm_audit.log" 2>/dev/null || echo 1)
    record HIGH "pnpm-cves" "$n production advisory(ies) — see data/agents/audit-sentinel/pnpm_audit.log"
  fi
fi

# ── 3. .env vs .env.example drift ──────────────────────────────────────────────
if [[ -f .env && -f .env.example ]]; then
  example_keys=$(grep -E '^[A-Z_][A-Z0-9_]*=' .env.example | cut -d= -f1 | sort -u)
  env_keys=$(grep -E '^[A-Z_][A-Z0-9_]*=' .env | cut -d= -f1 | sort -u)
  missing=$(comm -23 <(echo "$example_keys") <(echo "$env_keys") | head -20)
  if [[ -n "$missing" ]]; then
    n=$(echo "$missing" | wc -l)
    record MEDIUM "env-missing-keys" "$n key(s) in .env.example but missing from .env: $(echo "$missing" | tr '\n' ',' | sed 's/,$//')"
  fi
fi

# ── 4. Mutating public routes without throttle middleware ─────────────────────
# route:list sees the FULL middleware stack incl. chained ->middleware()
# lines and group middleware — line-greps produced false positives.
# Authed Jetstream/framework form routes (session + CSRF) are acceptable
# without throttle; PUBLIC endpoints are not — those are whitelisted here.
unthrottled_public=$(php artisan route:list --json 2>/dev/null | php -r '
// webhooks/stripe: guarded by Stripe signature verification (whsec), not
// IP-throttle — throttling would drop legitimate renewal-batch bursts.
$wl = "#^(broadcasting/auth|storage/.*|current-team|logout|teams.*|team-invitations/.*|user|user/.*|webhooks/stripe)$#";
$r = json_decode(stream_get_contents(STDIN), true) ?: [];
foreach ($r as $x) {
    $m = implode(",", $x["middleware"] ?? []);
    $v = $x["method"] ?? "";
    $u = $x["uri"] ?? "";
    if (preg_match("/POST|PUT|PATCH|DELETE/", $v)
        && stripos($m, "throttle") === false
        && stripos($m, "signed") === false
        && ! preg_match($wl, $u)) {
        echo $v . " " . $u . PHP_EOL;
    }
}' | head -20)
if [[ -n "$unthrottled_public" ]]; then
  n=$(echo "$unthrottled_public" | wc -l)
  record MEDIUM "routes-missing-throttle" "$n mutating route(s) without throttle middleware (sample in data/agents/audit-sentinel/routes_unthrottled.log)"
  echo "$unthrottled_public" > "$OUT/routes_unthrottled.log"
fi

# ── 5. Debug-looking routes ────────────────────────────────────────────────────
debug_routes=$(grep -nEi "Route::[a-z]+\(['\"]/(test|debug|tmp|dev-only|scratch|sandbox)" routes/web.php routes/api.php 2>/dev/null)
if [[ -n "$debug_routes" ]]; then
  n=$(echo "$debug_routes" | wc -l)
  record HIGH "debug-routes" "$n debug-looking route(s) in routes/*.php: $(echo "$debug_routes" | cut -d: -f1-2 | tr '\n' ',' | sed 's/,$//')"
fi

# ── 6. Hard-coded secrets-shaped strings in source ─────────────────────────────
secrets_hits=$(grep -rnE "(sk_live_[A-Za-z0-9]{16,}|pk_live_[A-Za-z0-9]{16,}|AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{36}|xox[baprs]-[A-Za-z0-9-]{16,})" \
  app/ config/ routes/ 2>/dev/null | head -5)
if [[ -n "$secrets_hits" ]]; then
  n=$(echo "$secrets_hits" | wc -l)
  record CRITICAL "leaked-secret" "$n secret-shaped string(s) in source — investigate immediately"
  echo "$secrets_hits" > "$OUT/secrets_hits.log"
fi

# ── 7. APP_DEBUG=true with APP_ENV=production ─────────────────────────────────
if [[ -f .env ]]; then
  env_val=$(grep -E "^APP_ENV=" .env | cut -d= -f2 | tr -d '"' | tr -d "'")
  debug_val=$(grep -E "^APP_DEBUG=" .env | cut -d= -f2 | tr -d '"' | tr -d "'")
  if [[ "$env_val" == "production" && "$debug_val" == "true" ]]; then
    record CRITICAL "debug-in-prod" "APP_ENV=production but APP_DEBUG=true in .env"
  fi
fi

# ── 8. Outbound HTTP hardening ─────────────────────────────────────────────────
# a) Every app/ Http:: call site must set an explicit ->timeout( somewhere in
#    the file — a hung upstream must never hold a worker forever.
# timeout( may open the chain (Http::timeout(...)) or sit mid-chain (->timeout(...)).
missing_timeout=""
while IFS= read -r f; do
  grep -Eq -- "(->|Http::)timeout\(" "$f" || missing_timeout+="$f,"
done < <(grep -rl "Http::" app/ --include="*.php" 2>/dev/null)
if [[ -n "$missing_timeout" ]]; then
  n=$(echo "${missing_timeout%,}" | tr ',' '\n' | wc -l)
  record MEDIUM "http-missing-timeout" "$n Http:: call site(s) without ->timeout(): ${missing_timeout%,}"
fi

# b) Regression tripwires for the SSRF hardening on operator-supplied webhook
#    URLs (AUDIT-2026-07-02): redirects disabled + connection pinned to the
#    vetted IPs. If either disappears from AutomationCaller, flag it.
caller="app/Runtime/Automation/AutomationCaller.php"
if [[ -f "$caller" ]]; then
  grep -q "'allow_redirects' => false" "$caller" \
    || record HIGH "webhook-follows-redirects" "AutomationCaller no longer disables redirect-following — SSRF guard bypass via 3xx (see AUDIT-2026-07-02)"
  # Match the option assignment, not the comment that mentions it.
  grep -q "CURLOPT_RESOLVE =>" "$caller" \
    || record HIGH "webhook-dns-rebinding" "AutomationCaller no longer pins connections to vetted IPs — DNS-rebinding TOCTOU reopened (see AUDIT-2026-07-02)"
fi

# ── Write findings + summary ───────────────────────────────────────────────────
overall="PASS"
[[ $critical -gt 0 ]] && overall="FAIL"
[[ $critical -eq 0 && $high -gt 0 ]] && overall="WARN"
[[ $critical -eq 0 && $high -eq 0 && $medium -gt 0 ]] && overall="WARN"

joined=$(IFS=,; echo "${findings[*]:-}")
cat > "$OUT/findings.json" <<EOF
{
  "ts": "$TS",
  "overall": "$overall",
  "critical": $critical,
  "high": $high,
  "medium": $medium,
  "low": $low,
  "findings": [$joined]
}
EOF

# Markdown summary
{
  echo "# Audit Sentinel — $overall"
  echo ""
  echo "**$TS** · $critical CRITICAL / $high HIGH / $medium MEDIUM / $low LOW"
  echo ""
  python3 - "$OUT/findings.json" <<'PYEOF'
import json, sys
d = json.load(open(sys.argv[1]))
icon = {"CRITICAL":"🔴","HIGH":"🟠","MEDIUM":"🟡","LOW":"⚪"}
for f in d["findings"]:
    print(f"- {icon.get(f['severity'],'·')} **{f['severity']}** `{f['check']}` — {f['detail']}")
if not d["findings"]:
    print("_No findings._")
PYEOF
} > "$OUT/summary.md"

cat "$OUT/summary.md"
update_status "idle" "$overall"
[[ "$overall" == "FAIL" ]] && exit 1 || exit 0
