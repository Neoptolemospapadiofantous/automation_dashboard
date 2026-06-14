---
description: Run the System Check (runtime health — disk, logs, queue, DB, Typesense) and interpret the result as an operator would. Free — runs inside this interactive Claude Code session, no claude --print.
---

You are the Hermes System Coordinator for automation_dashboard. Execute this workflow end-to-end inside the current interactive session — do NOT shell out to `claude --print` or `claude -p`.

## Step 1 — Run the collector

```
bash scripts/agents/system_check.sh
```

Produces `data/agents/system-check/findings.json`. Read it.

## Step 2 — Pull in operational context

In parallel via Bash:
- `tail -200 storage/logs/laravel.log` (if file exists)
- `php artisan queue:failed` (if any)
- `php artisan about` (Laravel runtime summary)
- `git log --oneline -5` (recent deploys / changes that might explain anomalies)

For each WARN or FAIL in the findings, investigate one level deeper:

| Check | Dig into |
|---|---|
| `disk` | What's consuming space? Largest dirs under `storage/`: `du -sh storage/*/`. Old backups, public/build artifacts? |
| `laravel-log` | What's the dominant error pattern? `grep -oE '\.(ERROR\|CRITICAL): [^{]+' storage/logs/laravel.log \| sort \| uniq -c \| sort -rn \| head` |
| `log-errors` | Same as above — name the top 3 error classes |
| `db-connection` | Read the db.log; is it a config issue, network, or auth? |
| `queue` | Which job classes dominate the backlog? `php artisan queue:work --once --tries=1` to see the next job |
| `failed-jobs` | What's failing repeatedly? `php artisan queue:failed` + sample one with `php artisan queue:retry <id>` proposed |
| `typesense` | Network reachable but maybe wrong API key? Check `TYPESENSE_API_KEY` env exists; suggest `php artisan scout:status` |
| `scheduler` | Is `* * * * * cd /path && php artisan schedule:run` in the system crontab? Suggest the entry. |

## Step 3 — Classify each anomaly

For each WARN/FAIL:
- **TRANSIENT** — likely a blip (e.g., one-off DB timeout). Note it, move on.
- **PERSISTENT** — recurring pattern, real problem. Recommend action.
- **DEGRADED** — service is up but limping (e.g., 78% disk, 200-job backlog). Action recommended but not urgent.
- **DOWN** — actually broken. Page someone.

## Step 4 — Write the session note

Path: `docs/hermes/SYSTEM-<YYYY-MM-DD_HH-MM>.md` (UTC).

Format:

```markdown
---
date: <YYYY-MM-DD>
type: system
overall: <PASS|WARN|FAIL>
collector_overall: <what the script reported>
tags: [hermes, system]
---
# System Check — <YYYY-MM-DD HH:MM UTC>

## Snapshot
<collector summary: pass/warn/fail counts>

## Anomalies

### Down (page-someone-now)
<empty or list>

### Persistent
<recurring patterns + recommended action>

### Degraded
<things that are working but trending wrong>

### Transient
<one-off blips, kept for trend awareness>

## Top error classes (from last 200 log lines)
<grep summary — count + error class>

## Failed jobs (top 5)
<job class + count + sample exception>

## Recommended actions
<ordered: do this first, then this>

## Quiet wins
<healthy checks worth calling out — disk fine, DB fast, queue empty, etc.>
```

## Step 5 — Update the index

Edit `docs/hermes/index.md`: under the appropriate `### YYYY-MM` heading, prepend:

```markdown
- YYYY-MM-DD HH:MM UTC — [[SYSTEM-YYYY-MM-DD_HH-MM|System]] — <overall>, <N> anomalies (<down> down / <persistent> persistent / <degraded> degraded)
```

## Step 6 — Embed visualization

Append to the SYSTEM note:

```markdown
## System check visualization

\`\`\`mermaid
graph LR
    Collector[System Check collector] --> Investigate[Dig into anomalies]
    Investigate --> Classify[Classify TRANSIENT/PERSISTENT/DEGRADED/DOWN]
    Classify --> Down[Down]
    Classify --> Persistent[Persistent]
    Classify --> Degraded[Degraded]
    Classify --> Transient[Transient]

    classDef fail fill:#fee2e2,stroke:#ef4444
    classDef warn fill:#fef3c7,stroke:#f59e0b
    classDef pass fill:#d4f4dd,stroke:#34a853

    class Down fail
    class Persistent fail
    class Degraded warn
    class Transient pass
\`\`\`
```

## Step 7 — Regenerate dashboard

```bash
composer hermes-dashboard
```

## Step 8 — Final report

End with ONE LINE: `system <overall>, <N> anomalies, docs/hermes/SYSTEM-<ts>.md`.

## What you do NOT do

- Do not restart services, kill processes, run migrations, or change config — those are operator decisions
- Do not delete logs to "fix" the disk warning — recommend rotation instead
- Do not retry failed jobs automatically — list them, let the operator decide

## Notes on cost

Subscription billing only. No `claude --print` invocations.
