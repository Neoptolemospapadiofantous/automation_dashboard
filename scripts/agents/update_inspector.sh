#!/usr/bin/env bash
# Update Inspector — surfaces outdated PHP + JS deps with major-bump highlights.
# Writes data/agents/update-inspector/findings.json + last_run.{json,log}
# No LLM. Free regardless of Anthropic billing rules.

set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="$REPO/data/agents/update-inspector"
TS=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
mkdir -p "$OUT"

update_status() {
  python3 "$REPO/scripts/agents/agent_status.py" \
    "$REPO/data/agents" "update-inspector" "$1" "$TS" "$2" 2>/dev/null || true
}

update_status "running" "started"
cd "$REPO"

# ── 1. composer outdated (direct deps only) ───────────────────────────────────
composer outdated --direct --format=json > "$OUT/composer_outdated.json" 2>"$OUT/composer_outdated.err" || true
php_total=$(python3 -c "import json; d=json.load(open('$OUT/composer_outdated.json')); print(len(d.get('installed', [])))" 2>/dev/null || echo 0)
php_major=$(python3 - "$OUT/composer_outdated.json" <<'PYEOF' 2>/dev/null || echo 0
import json, sys
d = json.load(open(sys.argv[1]))
def major(v): return v.lstrip('v').split('.')[0]
n = 0
for p in d.get('installed', []):
    cur = p.get('version', '0')
    lat = p.get('latest', '0')
    if cur and lat and major(cur) != major(lat):
        n += 1
print(n)
PYEOF
)

# ── 2. pnpm outdated ──────────────────────────────────────────────────────────
js_total=0; js_major=0
if command -v pnpm >/dev/null 2>&1; then
  pnpm outdated --format=json > "$OUT/pnpm_outdated.json" 2>"$OUT/pnpm_outdated.err" || true
  if [[ -s "$OUT/pnpm_outdated.json" ]]; then
    js_total=$(python3 -c "import json; d=json.load(open('$OUT/pnpm_outdated.json')); print(len(d))" 2>/dev/null || echo 0)
    js_major=$(python3 - "$OUT/pnpm_outdated.json" <<'PYEOF' 2>/dev/null || echo 0
import json, sys, re
d = json.load(open(sys.argv[1]))
def major(v):
    m = re.match(r'^v?(\d+)', v or '')
    return m.group(1) if m else '0'
n = 0
for name, info in (d.items() if isinstance(d, dict) else []):
    cur = info.get('current', '0')
    lat = info.get('latest', '0')
    if cur and lat and major(cur) != major(lat):
        n += 1
print(n)
PYEOF
    )
  fi
fi

# ── 3. Build top-N detail lists ────────────────────────────────────────────────
php_list=$(python3 - "$OUT/composer_outdated.json" <<'PYEOF' 2>/dev/null || true
import json, sys
d = json.load(open(sys.argv[1]))
out = []
for p in d.get('installed', [])[:15]:
    out.append({
        "name": p.get("name"),
        "current": p.get("version"),
        "latest": p.get("latest"),
        "description": (p.get("description") or "")[:80],
    })
print(json.dumps(out))
PYEOF
)
[[ -z "$php_list" ]] && php_list="[]"

js_list="[]"
if [[ -s "$OUT/pnpm_outdated.json" ]]; then
  js_list=$(python3 - "$OUT/pnpm_outdated.json" <<'PYEOF' 2>/dev/null || true
import json, sys
d = json.load(open(sys.argv[1]))
out = []
items = list(d.items())[:15] if isinstance(d, dict) else []
for name, info in items:
    out.append({
        "name": name,
        "current": info.get("current"),
        "latest": info.get("latest"),
        "type": info.get("dependencyType", ""),
    })
print(json.dumps(out))
PYEOF
  )
  [[ -z "$js_list" ]] && js_list="[]"
fi

# ── 4. Overall verdict ─────────────────────────────────────────────────────────
overall="PASS"
total_major=$((php_major + js_major))
# Majors are upgrade DECISIONS, not drift — they warn, never fail.
# (Laravel 13 / PHPUnit 13 / Vite 8 / Tailwind 4 / Inertia 3 are all
# known-pending majors as of 2026-06-12; bump deliberately post-launch.)
[[ $total_major -gt 0 ]] && overall="WARN"

cat > "$OUT/findings.json" <<EOF
{
  "ts": "$TS",
  "overall": "$overall",
  "php_total": $php_total,
  "php_major": $php_major,
  "js_total": $js_total,
  "js_major": $js_major,
  "php": $php_list,
  "js": $js_list
}
EOF

# Markdown summary
{
  echo "# Update Inspector — $overall"
  echo ""
  echo "**$TS**"
  echo ""
  echo "- PHP: **$php_total** outdated ($php_major major-bump)"
  echo "- JS:  **$js_total** outdated ($js_major major-bump)"
  echo ""
  if [[ "$php_total" -gt 0 ]]; then
    echo "## PHP (top 15)"
    python3 - "$OUT/findings.json" <<'PYEOF'
import json, sys
d = json.load(open(sys.argv[1]))
for p in d["php"]:
    print(f"- `{p['name']}` {p['current']} → {p['latest']}")
PYEOF
  fi
  if [[ "$js_total" -gt 0 ]]; then
    echo ""
    echo "## JS (top 15)"
    python3 - "$OUT/findings.json" <<'PYEOF'
import json, sys
d = json.load(open(sys.argv[1]))
for p in d["js"]:
    print(f"- `{p['name']}` {p['current']} → {p['latest']} ({p['type']})")
PYEOF
  fi
} > "$OUT/summary.md"

cat "$OUT/summary.md"
update_status "idle" "$overall"
exit 0
