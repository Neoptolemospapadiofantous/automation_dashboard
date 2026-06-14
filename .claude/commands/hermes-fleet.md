---
description: Run the 5-agent automation_dashboard fleet (route-auditor, inertia-page-scanner, migration-watcher, voiceflow-surface-sentinel, doc-syncer) within this interactive session. Free under Anthropic's post-2026-06-15 billing — subscription, not the programmatic credit pool.
---

You are the Hermes Fleet Coordinator for automation_dashboard (Laravel 12 + Inertia/Vue 3 + Jetstream + Voiceflow). Execute the following workflow end-to-end. Read this entire file before starting — every step is required.

## Step 1 — Baseline

Run `composer hermes-fast` via Bash. After it finishes, read `data/hermes_findings.json` to capture the baseline `overall` status and the per-check findings list.

## Step 2 — Gather context + load safety filters (parallel)

In a single message, run in parallel:
- `git log --oneline -10`
- `git diff --name-only HEAD~5`
- List the two most recent fleet notes: `ls -t docs/hermes/FLEET-*.md | head -2`
- Read each of those two notes (head -30 each) for trend context (regressions, recurring findings)
- **Read `.hermes/suppressions.yaml`** if it exists — committed allowlist of intentional findings
- **Read `docs/hermes/decisions/*.md`** — active architectural decisions. A finding that contradicts an indexed decision must be surfaced as "challenges decision <slug>" instead of auto-applied.
- **Capture recent-touch list**: `git status --porcelain | awk '{print $2}'` plus `find app routes resources -type f -mmin -30 2>/dev/null` — files the user has touched in the last 30 minutes go straight to carry-forward (never auto-applied), to avoid stomping in-progress work
- **Capture revert history**: `git log --oneline --grep='Revert.*hermes' --since='30 days ago'` — list any reverts of prior fleet commits in the last 30 days. For each, find the original commit: `git log -1 --format='%H %s' <reverted-sha>` (the revert message contains the reverted SHA). Capture the reverted commit subjects. During Step 4 synthesis, a finding that would re-introduce something recently reverted goes to carry-forward, not auto-apply, with a `previously-reverted` flag.
- **Note the `@hermes-keep:` convention**: see `docs/hermes/conventions.md`. Any specialist finding whose `file:line` is within 5 lines of a `@hermes-keep:` comment is treated as **noted, not actionable** (carry-forward only). Annotation format: `// @hermes-keep: <reason>` (PHP/JS), `<!-- @hermes-keep: <reason> -->` (Vue template).

## Step 3 — Spawn the 5 specialists in parallel

Read `scripts/fleet_agents.json` to get the exact `prompt` field for each of the five agents. Then send ONE message containing FIVE Agent tool calls in parallel:

| Subagent label | `subagent_type` | Prompt source |
|---|---|---|
| `route-auditor` | `general-purpose` | `scripts/fleet_agents.json` → `route-auditor.prompt` |
| `inertia-page-scanner` | `general-purpose` | `scripts/fleet_agents.json` → `inertia-page-scanner.prompt` |
| `migration-watcher` | `general-purpose` | `scripts/fleet_agents.json` → `migration-watcher.prompt` |
| `voiceflow-surface-sentinel` | `general-purpose` | `scripts/fleet_agents.json` → `voiceflow-surface-sentinel.prompt` |
| `doc-syncer` | `general-purpose` | `scripts/fleet_agents.json` → `doc-syncer.prompt` |

Pass each agent its full prompt verbatim. Each specialist either reports findings (`route-auditor`, `inertia-page-scanner`, `migration-watcher`, `voiceflow-surface-sentinel`) or also applies its own targeted edits (`doc-syncer` — see its prompt).

## Step 4 — Synthesize and apply fixes

Collect all 5 reports. Bucket findings by severity:

- **CRITICAL** → fix now (security holes, broken auth, leaked credentials, exposed mutation routes)
- **HIGH** → fix now if it's straightforward (under ~10 min of edits, low blast radius)
- **MEDIUM / LOW** → record in the fleet note only

**Safety filter — apply IN THIS ORDER to every finding before deciding to apply:**

1. **Recent-touch guard** — if the finding's file appears in the recent-touch list from Step 2, send to carry-forward (never auto-apply). User is actively editing; do not stomp.
2. **Revert-aware** — if the proposed fix would re-introduce something whose original commit was reverted in the last 30 days (per the revert-history capture in Step 2), send to carry-forward with `previously-reverted: <revert SHA>: <subject>`. The user has already explicitly rejected this kind of change recently.
3. **`@hermes-keep:` annotation** — if the finding's `file:line` is within 5 lines of a `// @hermes-keep:` or `<!-- @hermes-keep: -->` comment, send to carry-forward and surface as "annotated as intentional: <quoted reason>".
4. **`.hermes/suppressions.yaml` match** — if `location:` matches the finding (exact path or glob), skip entirely. Surface as `[suppressed: <reason from yaml>]` in the carry-forward report.
5. **`docs/hermes/decisions/` conflict** — if a finding directly contradicts an active decision note (e.g. the workspace-key fallback was decided 2026-06-08; a `MISCONFIGURED_KEY_FALLBACK` finding would conflict), do NOT auto-apply. Surface as `challenges decision <slug>` in carry-forward. Resolution options for the operator: update the decision, accept the conflict, or ignore.
6. **HARD DO-NOT-TOUCH LIST** — refuse to edit even if a finding suggests it:
   - `.env*`
   - `config/database.php`
   - `config/services.php`
   - `database/migrations/**`
   - `app/Models/User.php`
   - `bin/deploy.sh`

   If a CRITICAL finding lands inside the DO-NOT-TOUCH list, leave it unfixed and flag it explicitly in the "Carry forward" section.
7. **Otherwise**: CRITICAL/HIGH → apply via Edit/Write directly; MEDIUM/LOW → carry-forward.

After applying fixes, scan `.hermes/suppressions.yaml` entries — any entry whose `location:` no longer matches a real codepath (e.g., file moved/deleted) is a stale_suppression — surface it in the carry-forward so the operator can prune.

## Step 5 — Verify

Re-run `composer hermes-fast`. Read `data/hermes_findings.json` again to capture the final `overall` status.

## Step 6 — Write the fleet note

Path: `docs/hermes/FLEET-<YYYY-MM-DD_HH-MM>.md` (UTC; ask Bash for `date -u +%Y-%m-%d_%H-%M` if needed).

Use this format exactly:

```markdown
---
date: <YYYY-MM-DD>
type: fleet
baseline_overall: <PASS|WARN|FAIL>
final_overall: <PASS|WARN|FAIL>
agents: [route-auditor, inertia-page-scanner, migration-watcher, voiceflow-surface-sentinel, doc-syncer]
tags: [hermes, fleet]
---
# Fleet Run — <YYYY-MM-DD HH:MM UTC>

## Baseline
<one-line summary of pre-fleet hermes state + the chief outstanding warnings>

## Agent reports
### Route Auditor
<bulleted findings, or "CLEAN">

### Inertia Page Scanner
<findings>

### Migration Watcher
<findings>

### Voiceflow Surface Sentinel
<findings>

### Doc Syncer
<findings + any files it edited directly>

## Actions taken
<bulleted file:line edits, or "None — all findings were MEDIUM/LOW or in the DO-NOT-TOUCH list">

## Verification
<final hermes overall status + delta from baseline>

## Carry forward
<MEDIUM/LOW findings worth tracking next run, including any DO-NOT-TOUCH-blocked CRITICAL/HIGH items>
```

## Step 7 — Update the index

Edit `docs/hermes/index.md`: under the appropriate `### YYYY-MM` heading (create if missing), prepend a new bullet linking the run, mirroring existing entries:

```markdown
- YYYY-MM-DD HH:MM UTC — [[FLEET-YYYY-MM-DD_HH-MM|Fleet run]] — baseline <X> → final <Y>, <N> fixes applied (<short summary>)
```

## Step 8 — Embed visualization

Append to the fleet note:

```markdown
## Fleet visualization

\`\`\`mermaid
graph TB
    C(Coordinator)
    C --> RA[route-auditor]
    C --> IS[inertia-page-scanner]
    C --> MW[migration-watcher]
    C --> VS[voiceflow-surface-sentinel]
    C --> DS[doc-syncer]

    classDef pass fill:#d4f4dd,stroke:#34a853
    classDef warn fill:#fef3c7,stroke:#f59e0b
    classDef fail fill:#fee2e2,stroke:#ef4444

    class RA <pass|warn|fail>
    class IS <pass|warn|fail>
    class MW <pass|warn|fail>
    class VS <pass|warn|fail>
    class DS <pass|warn|fail>
\`\`\`
```

Substitute the actual per-agent verdict based on each specialist's report (PASS = clean, WARN = findings only, FAIL = blocking issue).

## Step 9 — Regenerate dashboard

```bash
composer hermes-dashboard
```

## Step 10 — Final report

End your turn with ONE LINE: `baseline <X> → final <Y>, <N> fixes applied, docs/hermes/FLEET-<ts>.md`.

## Notes on cost

This entire workflow runs within the current interactive Claude Code session, so every sub-agent and tool call bills against the user's Claude subscription — not the post-2026-06-15 Agent SDK credit pool. Do NOT shell out to `claude --print` or `claude -p`; those invocations would be programmatic and bill differently. Use the Agent tool directly.
