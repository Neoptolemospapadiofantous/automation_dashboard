---
description: Run the Audit Sentinel (security + risk surface scan) and review its findings as an expert reviewer. Free — runs inside this interactive Claude Code session, no claude --print.
---

You are the Hermes Audit Coordinator for automation_dashboard. Execute this workflow end-to-end inside the current interactive session — do NOT shell out to `claude --print` or `claude -p`.

## Step 1 — Run the collector

Run via Bash:
```
bash scripts/agents/audit_sentinel.sh
```

This produces `data/agents/audit-sentinel/findings.json` and prints a markdown summary. Read the JSON to get the structured data.

## Step 2 — Interpret each finding

For every entry in `findings`:

1. Read the relevant source file (route file, .env, source dir) to confirm the finding is real and not a false positive
2. Classify the actual risk in your own words (the script's `severity` is a heuristic — refine it):
   - **CONFIRMED-CRITICAL** — exploitable or actively dangerous, must fix before next push
   - **CONFIRMED-HIGH** — real risk, fix this PR
   - **CONFIRMED-MEDIUM** — worth tracking, schedule cleanup
   - **FALSE POSITIVE** — explain why (e.g., the throttle is applied at route-group level, the "debug route" is gated by a deploy flag)
3. For CONFIRMED-CRITICAL or CONFIRMED-HIGH findings: propose a concrete fix (file:line + suggested change). **Do not auto-apply yet** — wait for human review unless the user explicitly asks you to apply.

## Step 3 — Look for what the script missed

The collector is a heuristic. As an interpreter, sweep for:
- Any `/api/` route handler that touches tenant data without `auth:sanctum`
- Any `Http::*` call without `->timeout()` or `->throw()` in `app/Services/`
- Any `whereRaw` / `DB::raw` with interpolated variables (SQL injection vector)
- Any `mass-assignment` risk: model with `$guarded = []` plus a controller that does `Model::create($request->all())`
- Stored credentials in `database/seeders/` or `tests/`

Report these as additional findings beyond what the collector produced.

## Step 4 — Write the session note

Path: `docs/hermes/AUDIT-<YYYY-MM-DD_HH-MM>.md` (UTC; get timestamp via `date -u +%Y-%m-%d_%H-%M`).

Format:

```markdown
---
date: <YYYY-MM-DD>
type: audit
overall: <PASS|WARN|FAIL>
collector_overall: <what the script reported>
tags: [hermes, audit]
---
# Audit — <YYYY-MM-DD HH:MM UTC>

## Collector summary
<one-line: counts + collector's overall>

## Confirmed findings

### Critical
<bulleted: check, file:line, why it's confirmed, proposed fix>

### High
<bulleted>

### Medium
<bulleted>

## False positives
<entries from the collector that you confirmed are not actually risks, with reason>

## Additional findings (collector missed)
<your sweep of routes/services/seeders/etc.>

## Suggested next actions
<ordered list — fix this first, then this, etc.>
```

## Step 5 — Update the index

Edit `docs/hermes/index.md`: under the appropriate `### YYYY-MM` heading, prepend:

```markdown
- YYYY-MM-DD HH:MM UTC — [[AUDIT-YYYY-MM-DD_HH-MM|Audit]] — <overall>, <N> critical / <M> high / <K> medium confirmed
```

## Step 6 — Embed visualization

Append to the AUDIT note:

```markdown
## Audit visualization

\`\`\`mermaid
graph LR
    Collector[Audit Sentinel collector] --> Confirm[Confirm vs source]
    Confirm --> Sweep[Sweep for collector blind spots]
    Sweep --> Report[Classify + suggest fixes]

    classDef pass fill:#d4f4dd,stroke:#34a853
    classDef warn fill:#fef3c7,stroke:#f59e0b
    classDef fail fill:#fee2e2,stroke:#ef4444

    class Report <pass|warn|fail>
\`\`\`
```

## Step 7 — Regenerate dashboard

```bash
composer hermes-dashboard
```

## Step 8 — Final report

End with ONE LINE: `audit <overall>, <N> critical / <M> high / <K> medium confirmed, docs/hermes/AUDIT-<ts>.md`.

## Notes on cost

All tool calls (Bash, Read, Edit, Write) run inside the current interactive Claude Code session — subscription billing, unaffected by the post-2026-06-15 Agent SDK credit pool. Do not invoke `claude --print` or `claude -p` from this workflow.

## HARD DO-NOT-TOUCH

If you're tempted to apply a fix: `.env*`, `config/database.php`, `config/services.php`, `database/migrations/**`, `app/Models/User.php`, `bin/deploy.sh`. Flag findings in these files in the session note rather than editing them.
