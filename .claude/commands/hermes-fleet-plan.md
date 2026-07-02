---
description: Plan-only variant of /hermes-fleet — spawn the 5 specialists, synthesize findings, but DO NOT apply fixes. Writes a reviewable plan to data/agents/fleet/<TS>/PLAN.md for /hermes-fleet-apply to pick up later. Use when you want to see what the fleet would do before letting it touch the working tree.
---

You are the Hermes Fleet Coordinator in **plan-only mode** for automation_dashboard. Run the full /hermes-fleet workflow EXCEPT do not Edit/Write any source file. The deliverable is a reviewable plan; the operator approves and applies via /hermes-fleet-apply.

## Workflow (mirrors /hermes-fleet through Step 4, diverges at Step 4b)

### Step 1 — Baseline
Run `composer hermes-fast` via Bash. Read `data/hermes_findings.json`.

### Step 2 — Gather context (parallel)
In ONE message:
- `git log --oneline -10`
- `git diff --name-only HEAD~5`
- `ls -t docs/hermes/FLEET-*.md | head -2` + read head -30 of each
- Read `.hermes/suppressions.yaml` if present
- Capture recent-touch list: `git status --porcelain | awk '{print $2}'` + `find app routes resources -type f -mmin -30 2>/dev/null`
- Read every `docs/hermes/decisions/*.md` for active architectural decisions
- Check revert history: `git log --oneline --grep='Revert.*hermes' --since='30 days ago'` — any reverts of prior fleet commits

### Step 3 — Spawn the 5 specialists in parallel
Same as /hermes-fleet — read `scripts/fleet_agents.json`, spawn one Agent tool call per agent in a single message:
- `route-auditor`, `inertia-page-scanner`, `migration-watcher`, `runtime-surface-sentinel`, `doc-syncer`

Important: tell `doc-syncer` to **report only, do not apply edits** by prepending its prompt with "PLAN MODE: report findings only; do not Edit/Write any file."

### Step 4 — Synthesize
Collect all 5 reports. Apply the safety filter (recent-touch → @hermes-keep → suppressions.yaml → DO-NOT-TOUCH) AND cross-reference against `docs/hermes/decisions/`. For each finding mark:
- `WOULD_APPLY` — passes all filters, severity ≥ HIGH
- `CARRY_FORWARD` — passes filters but MEDIUM/LOW severity
- `SKIPPED_SAFETY` — caught by a filter; include WHICH filter
- `CHALLENGES_DECISION` — contradicts an entry in `docs/hermes/decisions/`; surface the decision slug

### Step 4b — Write the PLAN (do NOT Edit anything)

Create the session dir + plan:
```bash
TS=$(date -u +%Y-%m-%d_%H-%M)
mkdir -p data/agents/fleet/$TS
```

Write `data/agents/fleet/<TS>/PLAN.md`:

```markdown
---
date: <YYYY-MM-DD>
type: fleet-plan
status: pending-review
agents: [route-auditor, inertia-page-scanner, migration-watcher, runtime-surface-sentinel, doc-syncer]
tags: [hermes, fleet, plan]
---
# Fleet PLAN — <YYYY-MM-DD HH:MM UTC>

**Apply with**: `/hermes-fleet-apply <TS>` from any interactive Claude Code session in this repo.

## Baseline
<one line: hermes-fast PASS/WARN/FAIL counts>

## Proposed actions (would_apply)

For each CRITICAL/HIGH finding that passes all safety filters:

### <severity> <CHECK-TAG>: <file:line>
- **Source**: <which specialist flagged it>
- **Finding**: <quoted from specialist report>
- **Proposed fix**: <exact change>
- **Rationale**: <one sentence on why>
- **Risk**: <what could go wrong>
- **Diff** (preview):
  ```diff
  - <old>
  + <new>
  ```

## Skipped by safety filter

| Finding | File | Filter | Reason |
|---|---|---|---|
| <tag> | <path> | recent-touch / @hermes-keep / suppressions / DO-NOT-TOUCH | <reason> |

## Challenges existing decisions

For findings that contradict `docs/hermes/decisions/`:

| Finding | Conflicting decision | Resolution suggested |
|---|---|---|
| <quoted finding> | [[<decision-slug>]] | <update decision / accept conflict / ignore> |

## Carry forward (MEDIUM/LOW)

<bulleted list>

## Stale suppressions

<entries in .hermes/suppressions.yaml whose location: no longer matches any real file:line>

## Revert-aware notes

If any proposed fix re-applies something that was reverted in the last 30 days (per git log), flag it:
<commit hash> reverted <subject> on <date>; proposed action would reintroduce this — review before approving.
```

Also write `data/agents/fleet/<TS>/02-fixes.json` with the structured action list so `/hermes-fleet-apply` can read it without re-parsing markdown:

```json
{
  "ts": "<TS>",
  "would_apply": [
    {"file": "...", "line": 87, "change": "added throttle:30,1", "severity": "HIGH", "agent": "route-auditor"}
  ],
  "skipped": [...],
  "challenges_decisions": [...],
  "carry_forward": [...]
}
```

### Step 5 — Final report

End your turn with TWO LINES:
```
plan written: data/agents/fleet/<TS>/PLAN.md  (review it, then /hermes-fleet-apply <TS> to commit)
counts: <N> would_apply, <M> skipped, <K> challenges, <P> carry_forward
```

## What you do NOT do in plan mode

- NO `Edit` or `Write` calls against any source file (.php, .vue, .js, .yaml at the project root, etc.)
- NO running `composer hermes-fast` a second time (no fixes were applied — nothing to re-verify)
- NO writing to `docs/hermes/FLEET-*.md` (the canonical fleet note happens at apply time, not plan time)
- NO `composer hermes-dashboard` regen (dashboard reflects committed runs, not plans)

The only writes allowed in plan mode are inside `data/agents/fleet/<TS>/` — gitignored, transient, throwaway-able.

## Notes on cost

This runs entirely inside the current interactive Claude Code session — subscription billing, no credit pool. Same as `/hermes-fleet`. Do NOT shell out to `claude --print` or `claude -p` anywhere.
