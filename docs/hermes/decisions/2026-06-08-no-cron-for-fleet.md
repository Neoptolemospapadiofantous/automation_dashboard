---
date: 2026-06-08
type: decision
status: active
tags: [hermes, decisions, billing, anthropic, safety]
---

# Fleet runs are never scheduled — always interactive or explicit

## Context

Anthropic's June 15, 2026 billing change split subscription usage into two pools:

- **Interactive** (Claude Code TUI, Claude.ai web/desktop, Cowork) — unchanged subscription allowance
- **Programmatic** (Agent SDK, `claude -p` / `claude --print`, GitHub Actions) — dedicated monthly credit pool (Pro $20, Max 5x $100, Max 20x $200), non-rollover, full API rates after exhaustion

Earlier in the project we built Hermes Desktop cron jobs to fire `claude --print` invocations of the fleet on a schedule. After June 15, every scheduled fleet run draws from the credit pool. Even at daily cadence, ~$45/mo against a $20/mo Pro pool would cost more than the subscription itself.

## Decision

No automatic / scheduled invocation of any LLM-spending hermes operation. All paths require explicit human action:

- `composer hermes-fast` — always free (no LLM); can be scheduled, but it's also fine to leave manual
- `/hermes-fleet`, `/hermes-docs`, `/hermes-audit`, `/hermes-update`, `/hermes-system`, `/hermes-lifecycle` — slash commands; only callable from an interactive Claude Code session, which IS billed against the unchanged subscription pool
- `bash scripts/hermes_fleet.sh` (the legacy `claude --print` path) — **deleted** in Phase G; no headless escape hatch

## Rationale

- **Interactive-only ⟹ subscription-billed** — the slash command surfaces fire from within a TTY-attached Claude Code session, which Anthropic's policy explicitly carves out as unchanged
- **No cron ⟹ no surprise spend** — even with the carve-out, a scheduler firing claude-anything is an unbounded liability if Anthropic ever tightens the rules
- **Manual invocation is fast** — `composer hermes-fast` returns in seconds; a human typing it before a push is no friction
- **Hermes Desktop gateway uninstalled** — also closes a separate route: Hermes Desktop's OAuth integration mis-routes to an empty `extra_usage` billing pool (issue #12905), so even an "interactive-shaped" cron through Hermes would have leaked credits

## Alternatives rejected

| Option | Why no |
|---|---|
| Daily cron of `/hermes-fleet` | Even at subscription billing, schedules drift; "I forgot we run this nightly" is a real failure mode |
| Pre-commit hook running `claude --print` | Slow + programmatic-billed + would force every commit to wait for a fleet pass |
| Background daemon (like rithmic's `hermes_lifecycle.sh`) | Long-running invocations are exactly where the programmatic / interactive boundary blurs; safer to not test it |
| Schedule only the no-LLM bits | Possible but adds complexity for marginal value — `composer hermes-fast` is already 8 seconds and free |

## Consequences

- The hermes system is "always-ready, never-firing" — no scheduler, no daemon, no autonomous loop
- Operator discipline required: invoke `composer hermes-fast` before pushes, `/hermes-fleet` before merges or weekly
- `docs/hermes/README.md` and `docs/hermes/index.md` describe this explicitly so future contributors don't add a scheduler "to help"
- The trade-off — manual operation — is accepted as the cost of a predictable bill

## Related

- `docs/hermes/README.md` — operational doc with the June 15 TODO and billing context
- `docs/voiceflow/wrapper-plan.md` — references the post-June-15 cost model throughout
- Phase G's deletion of `scripts/hermes_fleet.sh` and uninstall of `hermes-gateway.service`
