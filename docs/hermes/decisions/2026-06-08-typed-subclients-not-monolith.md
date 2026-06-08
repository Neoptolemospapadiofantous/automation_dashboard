---
date: 2026-06-08
type: decision
status: active
tags: [hermes, decisions, voiceflow, architecture]
---

# Voiceflow wrapper is per-host typed subclients, not a monolithic service

## Context

Voiceflow exposes endpoints across four distinct hosts:

- `general-runtime.voiceflow.com` — session, interact, state, KB query, streaming
- `analytics-api.voiceflow.com` — transcripts, evaluations, usage
- `realtime-api.voiceflow.com` — KB CRUD, projects, environments
- (and the inbound webhook surfaces — different concern)

Each host has different auth (DM key vs workspace key), different request-shape conventions, and different rate-limit characteristics.

Pre-Phase 15, `VoiceflowService` held all 24 wrapper methods directly — DM and workspace auth mixed by convention rather than by binding, base URLs and timeouts repeated per-method with divergent values.

## Decision

Split into four host-specific typed subclients under `app/Services/Voiceflow/Client/`:

- `RuntimeClient` — DM-key surfaces (session, interact, state, KB query)
- `AnalyticsClient` — workspace-key analytics surfaces (transcripts, evaluations, usage)
- `RealtimeClient` — workspace-key realtime surfaces (KB CRUD, environments)
- `StreamingClient` — composes RuntimeClient + adds SSE parsing for `/v4/interact/stream`

A shared `VoiceflowHttpClient` factory handles `PendingRequest` construction (timeouts, retries, ensureOk → typed exceptions, structured logging) so every subclient has consistent transport behaviour.

`VoiceflowService` is preserved as the legacy entry point — its public signatures are stable, methods delegate to typed subclients internally so existing callsites and the 49 prior tests keep working unchanged.

## Rationale

- **Auth coupling enforced by binding, not convention** — passing the wrong key to a subclient is a constructor `MisconfiguredException`, not a 401 surprise hours later
- **Per-host concerns isolated** — `AnalyticsClient` knows about transcript pagination shape; `RealtimeClient` knows about KB upload size caps; neither leaks into the other
- **Tests are per-host** — `VoiceflowAnalyticsClientTest`, `VoiceflowRealtimeClientTest`, etc. — each tightly scoped, easy to extend
- **Adding new endpoints is local** — a new transcript endpoint is one method on `AnalyticsClient`; doesn't touch the other subclients or the legacy `VoiceflowService`
- **DI is straightforward** — `VoiceflowServiceProvider` binds each subclient as request-scoped against current agent; static `*For($agent)` factories serve CLI/jobs

## Alternatives rejected

| Option | Why no |
|---|---|
| Keep monolithic `VoiceflowService` | Auth mixing risk, divergent timeouts, hard to add coverage |
| Per-endpoint individual classes | Over-engineered; 42 classes for 42 methods |
| Single client + host-as-parameter | Loses the per-host typed exceptions and timeouts; just renames the problem |
| Direct controller-to-`Http::baseUrl(...)` calls | Where we started; 50+ duplicated HTTP-builder blocks |

## Consequences

- `VoiceflowService` is now a thin delegator with `typedAnalytics()` + `typedRealtime()` helpers — most methods are ~2 lines
- Public signatures unchanged; backward compat preserved for all callsites including controllers, jobs, console commands
- Future Voiceflow endpoints land on the right subclient; the coordinator's `voiceflow-surface-sentinel` agent reads all subclients recursively (Phase A pre-flight)
- The `VoiceflowHttpClient::ensureOk()` exception-mapping gives every subclient identical error semantics — `catch (RateLimitedException)` works whether you called Analytics or Realtime

## Related

- `app/Services/Voiceflow/Client/*` — the four typed subclients
- `app/Providers/VoiceflowServiceProvider.php` — DI bindings + static factories
- `docs/phase-15-voiceflow-wrapper.md` — full phase doc
- `docs/voiceflow/wrapper-plan.md` — original target architecture
