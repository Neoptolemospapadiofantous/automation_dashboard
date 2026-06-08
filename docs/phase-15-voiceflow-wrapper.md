---
type: phase
tags: [voiceflow, wrapper, phase-15]
status: shipped
date: 2026-06-08
supersedes: docs/phase-5-voiceflow.md
---

# Phase 15 — Full Voiceflow Wrapper

Lifts Voiceflow integration coverage from ~19% (9 endpoints, 2 partial) to full coverage of the documented surface. Centralizes HTTP, adds retries everywhere, fixes pagination, adds streaming, inbound webhooks, evaluations, environment management.

## What shipped

### `app/Services/Voiceflow/`

```
Voiceflow/
├── Client/
│   ├── VoiceflowHttpClient.php   Central PendingRequest factory + ensureOk() → typed exceptions + structured logging
│   ├── RuntimeClient.php         8 public methods — session, interact, state, KB query, 401 auto-recovery
│   ├── AnalyticsClient.php       14 public methods — transcripts (search/get/end/delete/stream), evaluations (8), usage
│   ├── RealtimeClient.php        20 public methods — KB CRUD (list/create/replace/patch/upload-table/delete/stream),
│   │                              environments (list/get/clone/delete/publish/export/traffic split)
│   └── StreamingClient.php       SSE wrapper for /v4/interact/stream
├── Dto/
│   └── Trace.php                 readonly value object for /v4/interact frames
├── Exceptions/
│   ├── VoiceflowException.php    abstract base, toLogContext()
│   ├── AuthException.php         401 / 403
│   ├── NotFoundException.php     404
│   ├── RateLimitedException.php  429 + retryAfterSeconds
│   ├── UpstreamException.php     5xx + connection failures
│   └── MisconfiguredException.php never-sent-the-request
```

### `app/Providers/VoiceflowServiceProvider.php`

- `VoiceflowHttpClient` — singleton
- `RuntimeClient`, `AnalyticsClient`, `RealtimeClient`, `StreamingClient` — request-scoped, resolved against current Agent
- `runtimeFor`, `analyticsFor`, `realtimeFor`, `streamingFor` static factories for CLI / jobs

### Inbound webhooks

- `POST /api/voiceflow/lead-captured/{agent:slug}` — existing Custom Action capture (unchanged)
- `POST /api/voiceflow/webhooks/session/{agent:slug}` — **new** session-lifecycle handler (`runtime.session.*`, `runtime.call.*`). Per-agent `X-Webhook-Secret`, persists to `voiceflow_webhook_events` with idempotency on `(agent_id, event_id)`, reactively updates `Conversation.{started_at, ended_at, status, voiceflow_transcript_id}`

### Migrations

- `2026_06_08_000001_create_voiceflow_webhook_events_table` — durable webhook log with composite indexes on `(agent_id, event_type, received_at)` for replay queries

### Controllers

- `ConversationController::endUpstream` — force-end stuck upstream sessions
- `ConversationController::deleteUpstream` — GDPR delete (upstream + local cascade)
- `KnowledgeBaseController::storeText` — text-paste KB document variant
- `Voiceflow\SessionLifecycleController` — inbound webhook handler

### Legacy `VoiceflowService`

Delegates to typed subclients via `typedAnalytics()` + `typedRealtime()` helpers. Public signatures preserved — all 49 prior tests still pass.

**Removed dead code**:
- `apiClient()`
- `statePath()`
- `analyticsClient()`
- `$apiUrl` property + `services.voiceflow.api_url` config key

**Added**:
- `endTranscript()`, `deleteTranscript()`, `queryUsage()`, `safeUsageCount()`
- `createKbTextDocument()`, `uploadKbTable()`, `replaceKbDocument()`, `patchKbDocument()`, `patchKbChunk()`
- 30-second cache on `getVariables()` — eliminates per-turn redundant round-trip

### Hermes audit integration (`scripts/fleet_agents.json`)

`voiceflow-surface-sentinel` now recursively reads `app/Services/Voiceflow/**` and `app/Http/Controllers/Voiceflow/**`. 4 new report tags: `UNTESTED_CLIENT_METHOD`, `WEAK_DTO`, `INCONSISTENT_EXCEPTION`, `WEBHOOK_MIDDLEWARE_GAP`.

### `composer voiceflow:coverage`

Generates `docs/voiceflow/coverage.md` — auto-built table of every wrapper method mapped to its upstream endpoint label. Regenerate after adding wrapper methods.

## Test coverage

Across phases A–E, **38 new tests** added:

| Test | Phase | Covers |
|---|---|---|
| `VoiceflowHttpClientTest` (7) | A | Status → exception mapping, log context |
| `VoiceflowAnalyticsClientTest` (4) | A | Pagination across 4 pages, 404→null |
| `VoiceflowRealtimeClientTest` (4) | A | KB pagination, oversized + empty upload rejection |
| `VoiceflowVariableCacheTest` (2) | A | 30s cache hit, project-scoped key |
| `VoiceflowTranscriptLifecycleTest` (4) | B | End/delete upstream + cross-tenant block |
| `VoiceflowKbTextTest` (2) | B | Text-paste KB document creation + validation |
| `VoiceflowSessionLifecycleWebhookTest` (6) | C | Secret rejection, disabled-agent, persistence, idempotency, conversation updates |
| `VoiceflowStreamingClientTest` (3) | C | Well-formed SSE, multi-line data, malformed-event skip |
| `VoiceflowEvaluationsTest` (6) | D | Create/list/get/run/queue + 429 → `RateLimitedException` with `Retry-After` |
| `VoiceflowEnvironmentsTest` (6) | E | List/get/clone/publish/export + traffic split read/write |

All 49 prior Voiceflow tests still pass — no breaking changes.

## Coverage delta

| Before | After |
|---|---|
| 9 endpoints fully wrapped | **42** public methods across 4 typed subclients |
| 2 partial (lossy state reduction, no streaming) | All canonical surfaces wrapped; lossy reductions retained as opt-in helpers |
| 36 unwrapped | **~all wrapped** — exports, evaluations, traffic split, projects, streaming, lifecycle webhooks, KB metadata |
| No retries except session-start | Retries on every Voiceflow call via `VoiceflowHttpClient` |
| `file_get_contents` upload (10 MB into memory) | `fopen` stream + 10 MB cap enforced in client |
| Session-key wedge: stale cache for 1h | 401 auto-recovery — `forgetSession + retry once` |
| Per-turn `getVariables` round-trip | 30-second cache |
| `searchTranscripts` / `listKbDocuments` truncate at one page | `transcriptStream()` / `kbDocumentStream()` generators paginate to completion |
| No structured logging | `Log::channel('voiceflow').{debug|warning|error}` on every call |

## Known follow-ups (Phase G+)

- **Frontend streaming integration** — wrapper backend is ready; `Chat/Index.vue` needs `fetch + ReadableStream` swap to consume `/chat/interact/stream` (designed for next phase)
- **Svix HMAC for org-events webhooks** — requires adding `svix/svix` composer dep; `SessionLifecycleController` pattern is the template
- **Evaluations + Environments UI pages** — wrappers ready; `Pages/Agents/Evaluations.vue` and `Pages/Agents/Environments.vue` to follow
- **Webhook subscriptions** — Voiceflow has no public API for subscription management; must be configured in their dashboard

## Why this design

- **Typed subclients per host** — DM-key vs workspace-key separation enforced by binding, not by convention
- **Central HTTP factory** — single place to set timeouts, retries, headers, log context. No more divergent `15s vs 20s vs 30s` per method
- **Public signature stability** — `VoiceflowService` is the legacy entry, retains all method signatures, delegates internally
- **Exceptions over arrays** — controllers catch `AuthException` / `UpstreamException` / `RateLimitedException` instead of inspecting status codes
- **Pagination as a generator** — no silent truncation, no full-list-in-memory either
- **Cache invalidation on session reset** — `forgetSession()` clears both session key + 30s variables cache, no stale wedges

## Where to look next

- Coverage report: `docs/voiceflow/coverage.md`
- Wrapper plan: `docs/voiceflow/wrapper-plan.md`
- Architecture: `app/Services/Voiceflow/`
- Public API docs reference: `docs/voiceflow/`
