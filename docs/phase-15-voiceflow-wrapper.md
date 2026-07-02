---
type: phase
tags: [legacy-engine, wrapper, phase-15, superseded]
status: superseded
date: 2026-06-08
supersedes: docs/phase-5-voiceflow.md
---

# Phase 15 — Legacy conversational-engine HTTP wrapper (superseded)

> **Superseded history.** This phase hardened the platform's integration with a
> *third-party* conversational engine — the same engine introduced in
> [[phase-5-voiceflow|Phase 5]]. That engine has since been **fully removed** and
> replaced by the native runtime in `app/Runtime/` (`AgentRuntime`,
> `FlowExecutor`, `LlmRouter`), so the wrapper described here no longer exists in
> the codebase. This doc is kept only as a record of what was built and why. The
> engine-specific endpoint/wrapper detail it used to contain moved to the
> `docs/voiceflow/` archive, itself removed from the repo in `76ee819` —
> git history only.

## What this phase delivered

Phase 5 shipped an ad-hoc client that covered only a small slice (~19%) of the
third-party engine's documented surface. Phase 15 turned that into a full,
production-grade HTTP wrapper:

- **A central HTTP factory** — one place to set timeouts, retries, headers, and
  structured logging, replacing per-method divergence. Every call to the engine
  retried and logged consistently.
- **Typed subclients per host** — runtime (sessions / interact / state / KB
  query), analytics (transcript lifecycle, evaluations, usage), realtime (KB
  CRUD, environment management), and an SSE streaming wrapper. The split
  enforced credential separation by binding rather than convention.
- **A typed exception hierarchy** — auth / not-found / rate-limited /
  upstream / misconfigured — so controllers caught typed errors instead of
  inspecting status codes.
- **Pagination as generators** — no silent single-page truncation and no
  full-list-in-memory.
- **Inbound session-lifecycle webhook** — a per-agent, secret-verified handler
  that persisted engine session events idempotently and reactively updated the
  matching `Conversation` (`started_at` / `ended_at` / `status` /
  `transcript_id`).
- **Streaming, evaluations, and environment management** surfaced through the
  wrapper and backed by feature tests, plus a frontend streaming integration in
  `Chat/Index.vue` with capability detection and a non-streaming fallback.
- **Backward-compatible legacy entry point** — the original Phase 5 service kept
  its public signatures and delegated to the typed subclients, so existing tests
  kept passing through the refactor.

## Why it mattered / what survived the swap

The lasting lesson here is the same **engine seam** principle from Phase 5,
pushed further: by funnelling every third-party call through one typed,
centrally-configured wrapper, the entire integration had a single, well-defined
boundary. When the decision came to drop the third-party engine, there was one
clearly-bounded layer to remove rather than scattered HTTP calls throughout the
app — which is what made replacing it with the native runtime (`app/Runtime/`)
tractable.

Two design choices that outlived this engine and informed the native runtime:

- **Typed errors over status-code inspection** at the integration boundary.
- **Generators for paginated reads** instead of either truncation or
  load-everything.

The legacy `voiceflow_*` DB columns referenced by the webhook persistence here
(e.g. the conversation's transcript id) were renamed to `visitor_id` /
`session_key` / `transcript_id` when the engine was removed.

## Where the old detail went

The full per-method coverage map, the engine's endpoint catalogue, the wrapper
plan, and the webhook event schema that this phase documented in detail were
preserved in the `docs/voiceflow/` archive, which was later removed from the
repo (`76ee819`) — recover it via git history if the historical specifics are
ever needed.
