---
description: Sync docs/phase-*.md, docs/architecture/*.md, and docs/public-surface.md against current code; apply safe doc-only fixes. Single agent, runs inside this interactive Claude Code session (free under post-2026-06-15 subscription billing — no claude --print invocation).
---

You are the Hermes Docs Coordinator for automation_dashboard. Execute the following workflow end-to-end inside this session — do NOT shell out to `claude --print` or `claude -p`, and do NOT spawn a subagent for the work itself (single-agent: you do the doc-syncing directly so the whole turn stays within the interactive subscription billing).

## Step 1 — Gather context (parallel)

In a single message, run in parallel:
- `git log --oneline -15`
- `git diff --name-only HEAD~10`
- List any prior docs-sync notes: `ls -t docs/hermes/DOCS-*.md 2>/dev/null | head -2`
- If prior notes exist, read the most recent one (head -30) for trend context

## Step 2 — Read the docs surface

Read in parallel:
- `docs/README.md` — top-level index
- `docs/architecture/integration-map.md`, `docs/architecture/landing-sse-pipeline.md`, `docs/architecture/public-stats-flow.md`
- `docs/public-surface.md`
- All `docs/phase-*.md` files (skim each)

## Step 3 — Cross-check against current code

Apply the doc-syncer rules (lifted verbatim from `scripts/fleet_agents.json`):

> Your job: find docs that are out of sync with the code and fix them.
>
> Steps:
> 1. Read docs/README.md and the docs/architecture/ files (integration-map.md, landing-sse-pipeline.md, public-stats-flow.md). Do referenced file paths still exist? Do referenced controllers/models/services still exist?
> 2. Read docs/public-surface.md. Cross-check against routes/web.php and routes/api.php — are listed public routes still public? Are there new public routes not documented?
> 3. Read each docs/phase-*.md. Does it reference files, classes, or migrations that have since been renamed/deleted? Latest git activity: `git -C /home/theone/automation_dashboard log --oneline -15`
> 4. For each doc file:
>    a. If a file path or class name is stale → fix the reference directly (Edit the doc).
>    b. If a whole section describes removed functionality → mark with `> **Note (auto-synced YYYY-MM-DD):** this section is stale` and remove the inaccurate content (do NOT delete the whole file).
>    c. If a recently-merged feature has no doc home → don't create new docs unprompted; just report it.

For each finding, classify:

- **FIXED**: `docs/<path>` — what was updated (you Edit'd the file directly)
- **STALE**: `docs/<path>:<section>` — references removed/renamed `<thing>` (couldn't auto-fix safely)
- **UNDOCUMENTED**: `<feature/route/service>` — merged in `<commit>` but no doc entry exists
- **OK**: `<doc>` — accurate, no changes needed

## Step 4 — Apply safe fixes

For each FIXED-class finding, apply via Edit directly. Constraints:

**HARD DO-NOT-TOUCH LIST:**
- `docs/audit/` (entire directory)
- `docs/phase-1-foundation.md` (historical record, immutable)
- Any non-docs file (you only edit `docs/`)
- `.env*`, `config/**`, anything outside `docs/`

If a stale reference points to a removed feature: insert the auto-synced `> **Note (auto-synced YYYY-MM-DD):**` callout above the affected paragraph rather than rewriting the whole section. Preserve the original text as historical record.

Do NOT create new doc files unprompted. UNDOCUMENTED findings stay as report items only.

## Step 5 — Write the session note

Path: `docs/hermes/DOCS-<YYYY-MM-DD_HH-MM>.md` (UTC; get the timestamp via `date -u +%Y-%m-%d_%H-%M` if needed).

Use this format exactly:

```markdown
---
date: <YYYY-MM-DD>
type: docs
overall: <PASS|WARN|FAIL>
agents: [doc-syncer]
tags: [hermes, docs]
---
# Docs Sync — <YYYY-MM-DD HH:MM UTC>

## Scope
<one line: how many docs checked, span of git history examined>

## Findings

### Fixed
<bulleted list of FIXED items with file:section + summary of edit, or "None — all docs accurate">

### Stale (not auto-fixed)
<bulleted list of STALE items — file:section + what's wrong + why it wasn't auto-fixed>

### Undocumented
<bulleted list of UNDOCUMENTED features/routes/services with their commit, or "None">

### OK
<bulleted list of docs confirmed accurate>

## Carry forward
<UNDOCUMENTED items worth creating a phase doc for next time, or STALE items needing human judgment>
```

`overall` heuristic:
- **PASS**: no STALE, no UNDOCUMENTED, and either no FIXED or only trivial path corrections
- **WARN**: any STALE or UNDOCUMENTED findings (humans should follow up), or non-trivial FIXED edits worth reviewing
- **FAIL**: structural drift severe enough that the docs no longer describe a runnable system (rare — reserve for actual emergencies)

## Step 6 — Update the index

Edit `docs/hermes/index.md`: under the appropriate `### YYYY-MM` heading (create if missing), prepend a new bullet linking the run, mirroring existing entries:

```markdown
- YYYY-MM-DD HH:MM UTC — [[DOCS-YYYY-MM-DD_HH-MM|Docs sync]] — <overall>, <N> fixed / <M> stale / <K> undocumented
```

## Step 7 — Embed visualization

Append to the DOCS note:

```markdown
## Docs sync visualization

\`\`\`mermaid
graph LR
    Scan[Scan docs] --> Compare[Cross-check vs code]
    Compare --> Fix[Apply safe fixes]
    Fix --> Report[Report stale + undocumented]

    classDef pass fill:#d4f4dd,stroke:#34a853
    classDef warn fill:#fef3c7,stroke:#f59e0b
    classDef fail fill:#fee2e2,stroke:#ef4444

    class Fix <pass|warn|fail>
\`\`\`
```

## Step 8 — Regenerate dashboard

```bash
composer hermes-dashboard
```

## Step 9 — Final report

End your turn with ONE LINE: `docs sync <overall>, <N> fixed / <M> stale / <K> undocumented, docs/hermes/DOCS-<ts>.md`.

## Notes on cost

This entire workflow runs within the current interactive Claude Code session — every tool call (Read, Edit, Bash, Write) bills against the user's Claude subscription, not the post-2026-06-15 Agent SDK credit pool. Do NOT shell out to `claude --print`.
