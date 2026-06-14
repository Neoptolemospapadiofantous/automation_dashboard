---
date: 2026-06-14
type: decision
status: active
tags: [hermes, decisions, learning, safety]
---

# The Hermes learning loop is periodic + human-reviewed, never automated

## Context

The Hermes audit system is a tree over a shared trunk (`docs/hermes/manifest.json`): deterministic checks → manifest-enriched findings graph → root synthesis. The natural next step ("increment 6") is a *learning loop* — feed the system's own outputs back to refine the trunk so it gets more accurate over time:

- mine run history for flaky checks, repeat-failing nodes, low-signal checks
- re-weight node `criticality`, add discovered `edges`, promote `checks_pending` to standalone checks
- grow a new granular check from each escaped defect (a bug that shipped while Hermes was green)

The open question was *cadence*: wire it into every run (continuous/automated) or run it on demand (periodic).

## Decision

The learning loop is **periodic and human-reviewed**, triggered by a person at a milestone — not scheduled, not hooked into per-run Hermes, and never self-applying its conclusions.

- **No per-run automation.** Nothing is added to `hermes.sh` or `ci.yml`. The verdict log (`data/logs/hermes_session.log`) already appends every run — that passive history is the only always-on piece.
- **Snapshot on demand.** The learning command captures the current findings graph into the ledger *when run*, so data points equal the review cadence, not every commit.
- **One manual command.** `/hermes-learn` (and/or `composer hermes-learn`) is invoked when the operator decides — after a batch of work, before a release, roughly monthly.
- **Proposes, never applies.** It emits a reviewable diff to the manifest (criticality/edges/checks) + suggested new checks. A human approves. Only safe, reversible deterministic facts (e.g. a flaky-flag) may auto-apply.

## Rationale

- **Diminishing returns on frequency.** Trend signal (flap, repeat-failure, dead-check) is meaningless until dozens of runs accumulate; re-running the analysis every commit just recomputes the same near-empty trend.
- **The trunk is slow-moving.** `criticality`/`edges`/`checks` change on the scale of *features*, not *commits* — reflecting after every run mostly yields "no change".
- **Continuous auto-tuning invites drift.** A loop that nudges criticality/suppressions every run will, over many small steps, quietly silence the checks that annoy it. Periodic + reviewed removes that failure mode.
- **Consistent with [[2026-06-08-no-cron-for-fleet]].** The heavy/LLM parts of Hermes are deliberately manual; learning belongs in that same bucket. (The reflection step is LLM-driven, so it's also subscription-billed only when run in an interactive session.)

## Alternatives rejected

| Option | Why no |
|---|---|
| Run the learning loop on every `hermes` run | Diminishing returns + per-run latency; the trend is unchanged commit-to-commit |
| Scheduled (nightly/weekly cron) reflection | Same no-cron reasoning as fleet; drift + "forgot we run this" failure mode |
| Auto-apply manifest tunings | The system would learn to silence inconvenient checks; unreviewed self-mutation of the gate is unacceptable |
| Append findings to the ledger on every run | Marginal data-granularity gain for an always-on write; on-demand snapshots are enough for milestone-level learning |

## Consequences

- Increment 6, when built, is a **manual command only** — no scheduler, no per-run hook, no autonomous loop.
- Learning quality depends on operator discipline (run it at milestones), accepted as the cost of a stable, drift-free gate.
- Manifest edits flow through human review, leaving a clear audit trail of *why* a weight/check changed.
- The always-on footprint stays zero beyond the existing verdict log.

## Related

- `docs/hermes/README.md` — the tree architecture (increments 1–5) this loop would close
- [[2026-06-08-no-cron-for-fleet]] — the prior "no automatic LLM-spending Hermes" decision this extends
- `docs/hermes/manifest.json` — the trunk the loop would propose edits to
