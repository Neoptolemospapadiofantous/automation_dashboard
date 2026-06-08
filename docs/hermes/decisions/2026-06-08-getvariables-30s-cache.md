---
date: 2026-06-08
type: decision
status: active
tags: [hermes, decisions, voiceflow, performance, caching]
---

# `getVariables()` caches for 30 seconds per `(project, user)`

## Context

`VoiceflowService::getVariables(userId)` hits `GET /state/user/{userId}` on the runtime host to read the agent's session variables (captured lead fields). Pre-Phase A, this fired on every `chat.interact` turn — doubling the upstream latency per turn (one POST `/v4/interact`, one GET `/state/user/{id}`).

## Decision

Cache the variables map for 30 seconds per cache key `vf_vars:{projectId}:{userId}`. Invalidated on `forgetSession()` (which fires on `launch()` resetting a conversation).

## Rationale

- **Per-turn duplication was a real win lost** — `chat.interact` flow consumes the same variables map twice in close succession (once to read lead fields, then again on the next turn's pre-flight). Both should share the cache.
- **30 seconds is the right window** — long enough to span a full agent turn (typical user reply time 5-15s); short enough that a Voiceflow-side variable update propagates within a single retry by the user
- **Project-scoped key prevents cross-tenant collisions** — multi-tenant safety: a user with the same external ID across two projects gets two distinct cache entries
- **`forgetSession()` invalidates correctly** — a fresh conversation `launch()` resets both the session-key cache AND the variables cache; stale data can't survive an explicit reset

## Alternatives rejected

| Option | Why no |
|---|---|
| No cache (status quo ante) | 2x latency per turn for unchanged data |
| Cache forever, invalidate on every `interact()` | Defeats the cache; `interact` is the hot path |
| Cache for the full session-key TTL (1 hour) | A user updating their email mid-conversation would not see the new value reflected in the local "captured fields" panel for up to an hour |
| Use Eloquent observer to invalidate when local `Lead` changes | Cache is upstream Voiceflow state, not local DB; observers wouldn't fire correctly |

## Consequences

- A captured-lead variable that changes server-side at Voiceflow (e.g., flow conditionally rewrites `name`) takes up to 30s to reflect in the dashboard's lead panel — acceptable for "captured field" semantics
- `forgetSession()` clears BOTH caches atomically (session key + variables) — single point of truth for "this user's session is fresh"
- Test: `VoiceflowVariableCacheTest` covers cache-hit (single upstream call from two reads) + project-scoped-key isolation

## Related

- `app/Services/VoiceflowService.php` — `getVariables()` with `Cache::remember`-equivalent flow
- `tests/Feature/VoiceflowVariableCacheTest.php`
