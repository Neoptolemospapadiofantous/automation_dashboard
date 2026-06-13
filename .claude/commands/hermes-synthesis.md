---
description: The Hermes root synthesis node. Reads the manifest-enriched findings graph and produces a prioritised, context-aware verdict — what's broken, what it threatens (blast radius), what to fix first, what's under-covered. Free — runs inside this interactive Claude Code session, no claude --print.
---

You are the **Hermes synthesis node** — the root of a tree of deterministic checks over `automation_dashboard`. The branches (checks) produce facts; your job is the prioritised, context-aware story. Execute end-to-end inside the current interactive session — do NOT shell out to `claude --print` / `claude -p`.

## Step 1 — Get a fresh findings graph

Run a Hermes pass so the graph is current (fast is fine):
```
composer hermes-fast
```
This runs the checks, enriches each finding with its manifest nodes / domains / blast radius / doc refs (`scripts/hermes_findings.py`), and writes the synthesis brief + prompt (`scripts/hermes_synthesis.py`).

Then read:
- `data/hermes_synthesis.md` — the deterministic context brief (findings ranked, domain health, coverage gaps)
- `data/hermes_synthesis_prompt.md` — the assembled prompt (brief + manifest domains)
- `docs/hermes/manifest.json` — the trunk, if you need a node's edges/docs/tests

## Step 2 — Produce the verdict

Using the brief as your facts, write a SHORT verdict for an engineer:

1. **Bottom line** — ship / investigate / blocked, one line, with the reason.
2. **Problems, ranked by REAL risk** — weight node `criticality` and blast radius: a `FAIL` on a high-criticality node whose `edges` fan into other domains outranks an isolated low one. For each problem:
   - what it **threatens** — walk its related nodes (e.g. a `margin-invariant` fail in `app/Billing` → its edges into `app/Runtime/LLM`, `app/Http/Controllers`)
   - **where to look first** — its `refs` (docs) and the test that guards it
   - confirm it's real by reading the cited source/test before escalating (the check status is a fact; the *severity* is your call)
3. **Coverage gaps** — the pending granular checks / untested nodes worth closing. Don't dwell if the run is green; a one-liner is enough.
4. Be terse. Don't re-list every finding — the brief already has them. Give the priority and the narrative.

## Notes
- This is advisory: it never changes the deterministic verdict (that's `composer hermes`'s exit code).
- The same graph powers the scoped runners: `python3 scripts/hermes_tree.py --domain <d>` re-runs one domain's deep checks if you need to reproduce a problem in isolation.
