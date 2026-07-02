# Phase 5 — Legacy conversational-engine integration (superseded)

> **Superseded history.** This phase built the platform's first integration
> with a *third-party* conversational engine. That engine has since been
> **fully removed** and replaced by the native runtime in `app/Runtime/`
> (`AgentRuntime`, `FlowExecutor`, `LlmRouter`). This doc is kept as a
> historical record of what the phase did and why. The third-party API/endpoint
> detail it used to contain moved to the `docs/voiceflow/` archive, which was
> itself removed from the repo in `76ee819` — git history only.

## What this phase delivered

Lead conversations were handled by a third-party conversational agent, proxied
entirely **server-side** so the engine's API key never reached the browser.
Fields captured during a conversation became leads on the board live.

- A **server-side engine client** — launched a conversation, sent user text,
  read back the agent's traces (text / quick-reply choices / end) and session
  variables, and extracted lead fields. The engine credentials were read from
  config and never exposed to the client.
- A **chat controller** (`/chat/launch`, `/chat/interact`) — proxied the
  conversation, turned engine traces into chat messages + quick-reply buttons,
  and **[[phase-3-leads|upserted a team-scoped `Lead`]]** from the captured
  fields. Broadcast `LeadMessage` (live transcript) and `LeadSaved` (board
  updates) on the private `team.{id}` channel.

  > [[phase-14-public-stats|Routes were renamed from `agent.*` → `chat.*` in Phase 14]]
  > to disambiguate from the agents-CRUD routes (`agents.*`). Same controller,
  > same behaviour, new URL + route name.
- An **inbound capture webhook** — let the engine push a qualified lead the
  instant it was captured, secured by a per-agent shared-secret header
  (`X-Webhook-Secret` = `Agent::$webhook_secret`).

  > [[phase-13-multitenancy|Phase 13 made this per-agent (was app-wide in Phase 5)]].
- A **Vue chat panel** (`Pages/Chat/Index.vue`) — start a conversation, send
  replies, tap quick-reply buttons, and watch captured lead fields populate live
  with a link through to the board.
- A **"Chat" nav link** and feature tests with HTTP faking.

## Why it mattered / what survived the swap

The durable design idea from this phase was the **engine seam**: all
third-party specifics (session handling, trace parsing, variable → lead-field
mapping) sat behind one server-side client and one controller. Nothing in the
lead pipeline, the board, or the broadcast layer knew which engine was behind
the seam.

That abstraction is exactly what made the later migration cheap — the
third-party engine was lifted out and the native runtime (`app/Runtime/`)
dropped in behind the same seam, with the lead-capture contract unchanged. The
legacy `voiceflow_*` DB columns from this era were renamed to `visitor_id` /
`session_key` / `transcript_id` as part of that removal.

## Where the old detail went

The engine-specific API contract, session/interact flow, trace types, and
configuration keys that this phase originally documented were preserved in the
`docs/voiceflow/` archive, which was later removed from the repo (`76ee819`) —
recover it via git history if the historical specifics are ever needed.

## Next

[[phase-7-delegation|Phase 6/7: delegation engine (assignment rules, presence) and a transcripts backfill]]
for full conversation history/audit.
