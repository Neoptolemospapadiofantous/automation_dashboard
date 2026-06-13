---
description: The Hermes learning loop. Mines history + git for signals (escaped bugs lacking a guarding check, untested nodes, flaky checks) and proposes manifest improvements — which you validate and apply as a human-reviewed diff. PERIODIC, never automated (2026-06-14 decision). Free — runs inside this interactive Claude Code session.
---

You are the **Hermes learning loop** for `automation_dashboard`. The deterministic checks produce facts; the metrics report shows whether they help; this loop closes the cycle — it feeds signals back to refine the **manifest trunk** so the audit gets more accurate over time. Run this **periodically** (a milestone, ~monthly), not every commit — the signal needs accumulated history and the trunk moves slowly.

**Hard rule (per `docs/hermes/decisions/2026-06-14-learning-is-periodic-not-automated.md`): propose, don't silently mutate.** You may *edit* the manifest, but leave it as a reviewable diff for the human to approve/commit — never auto-commit, and never silence a check to make a finding go away.

Execute end-to-end inside this session — no `claude --print`.

## Step 1 — Generate the proposals

```
composer hermes-learn
```
This snapshots the current findings graph into `data/hermes_history.jsonl` (per-check trends accumulate at your cadence) and writes `data/hermes_learn_proposals.md`. Read that file. Also read `docs/hermes/manifest.json` (the trunk) and, if useful, `data/hermes_metrics.json` (escape/catch context).

## Step 2 — Validate each proposal against the code

Treat the deterministic proposals as *candidates*, not orders. For each:

- **Escaped-bug node with no granular check** — read the escaping fix(es) and the node's code. Is the bug class worth a dedicated guard? If yes: write a regression test that would have caught it, and add a granular check to the manifest's `checks` block (target = that test) + the check name to the node's `checks`. If the bug was a one-off, say so and skip — don't add a check just because the heuristic flagged it.
- **Untested subsystem** — is it genuinely untestable boilerplate (→ `waived` with a reason in the manifest) or a real gap (→ write a test + add it to the node's `tests`)?
- **Flaky / repeat-failing check** — find the root cause before acting. Flaky → fix the non-determinism, don't just flag it. Repeat-failing on a node → consider bumping that node's `criticality`.

## Step 3 — Apply as a reviewable diff

Make the validated edits (manifest + any new tests), run `composer hermes-fast` (and `composer hermes-tree --domain <affected>` for any new granular check) to confirm green, then **summarise the diff and stop** — present it for the human to review and commit. Do NOT commit or push.

## Notes
- Bias toward *adding guards for real misses* and *closing genuine coverage gaps*. Be skeptical of criticality bumps and very skeptical of anything that weakens a check.
- If history is thin (< 3 snapshots), the stability section will say so — that's expected; the escaped-bug + coverage signals are still actionable on the first run.
