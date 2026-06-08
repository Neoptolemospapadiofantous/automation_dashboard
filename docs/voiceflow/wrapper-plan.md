---
type: plan
tags: [voiceflow, plan, architecture]
date: 2026-06-07
status: proposed
---

# Voiceflow Full Wrapper — Implementation Plan

## Where we are

**Current coverage: ~19%** of documented Voiceflow capabilities. 9 endpoints fully wrapped, 2 partial, 36 unwrapped.

| Domain | Wrapped | Unwrapped |
|---|---|---|
| Conversations (V4) | 4 / 12 | streaming, websocket, state mutation, traffic-split start, mid-conversation events |
| Knowledge Base | 5 / 9 | replace, doc-metadata patch, chunk-metadata patch, table upload |
| Transcripts | 2 / 4 (+ 8 property/value endpoints all NO) | end, delete, all transcript-property endpoints |
| Evaluations | 0 / 8 | entire surface |
| Analytics / Usage | 0 / 1 | `/v2/query/usage` |
| Projects / Environments | 0 / 8 | list, get, clone, publish, export, traffic split |
| Inbound webhooks | 0 / 7 event types | session-lifecycle, org events |

## What works well

- Per-agent credential resolution (`VoiceflowService::forAgent`)
- Clean DM-key vs workspace-key separation (`workspaceKey()` helper)
- Webhook auth uses `hash_equals` (timing-safe)
- Scoped DI binding per request
- 7 test files / 14+ test methods covering the existing surface
- Encrypted Eloquent casts on credential columns
- Graceful degradation in `VoiceflowController::respond` (errors → `report()` not user-visible)

## What's broken / fragile

### Reliability
- **Retries inconsistent** — only `startSessionResponse` retries; all other HTTP calls drop the turn on a transient blip
- **No logging anywhere** in the service; debugging tenant-specific 4xx requires local reproduction
- **Repeated HTTP-client construction** — `interact`, `getVariables`, `queryKnowledgeBase` each build their own `Http::baseUrl(...)->withHeaders(...)` chain with divergent timeouts (15s vs 20s vs 30s, no rule)
- **Stale session-key cache** — `sessionKey()` caches 1h; if Voiceflow expires/invalidates, user is wedged for up to an hour with no recovery path
- **`createKbFileDocument` uses `file_get_contents`** — loads the entire (up to 10 MB) upload into memory; no error check on read

### Correctness
- **Pagination broken** — `searchTranscripts` and `listKbDocuments` each return one page; tenants with >100 transcripts or >50 KB docs silently lose history
- **Per-turn `getVariables` doubles latency** — `VoiceflowController::respond` calls `interact` then immediately `getVariables` (full state fetch) to read 4 lead fields
- **`queryKnowledgeBase` drops fields** — strips chunk metadata (`type`, `documentName`) when reshaping
- **`getVariables` lossily reduces** the state response to `variables{}` only — `turn`, `stack`, `storage` discarded
- **Misleading operator hint** — 404 maps to "Check VOICEFLOW_VERSION_ID" but V4 doesn't use version IDs

### Security
- **Global `VOICEFLOW_WEBHOOK_SECRET` env fallback** seeded into backfilled agents — if the env value ever leaked, those agents are compromised
- **No min-length enforcement** on `webhook_secret`
- **Throttle on lead-capture webhook is per-IP**, not per-agent — noisy neighbor on shared egress can DoS another tenant's webhook

### Dead code
- `apiClient()` (protected, never called)
- `statePath()` (protected, never called)
- `services.voiceflow.api_url` (config key read but never consumed)

## Target end-state

100% of capabilities Voiceflow exposes are reachable via a typed PHP method. Controllers/jobs never touch raw arrays. Streaming chat. Inbound webhooks. Centralized HTTP client. Custom exception hierarchy.

### Target architecture

```
app/Services/Voiceflow/
├── Client/
│   ├── VoiceflowHttpClient.php     # Central PendingRequest factory: host + key + retry + logging
│   ├── RuntimeClient.php           # interact, session, state, KB query (host: general-runtime)
│   ├── AnalyticsClient.php         # transcripts, evaluations, usage   (host: analytics-api)
│   ├── RealtimeClient.php          # KB CRUD, projects, environments   (host: realtime-api)
│   └── StreamingClient.php         # SSE wrapper for /v4/interact/stream
├── Dto/
│   ├── Trace.php                   # speak/choice/end/visual/etc.
│   ├── KbDocument.php, KbChunk.php
│   ├── Transcript.php, TranscriptLog.php
│   ├── Evaluation.php, EvaluationResult.php
│   ├── Environment.php, TrafficSplit.php
│   └── UsageQueryResult.php
├── Exceptions/
│   ├── VoiceflowException.php      # base (interface)
│   ├── UpstreamException.php       # 5xx
│   ├── AuthException.php           # 401 / 403
│   ├── NotFoundException.php       # 404
│   ├── RateLimitedException.php    # 429 (+ retry-after)
│   └── MisconfiguredException.php
├── Webhooks/
│   ├── SessionLifecycleHandler.php # runtime.session.*, runtime.call.*
│   ├── OrgEventHandler.php         # organization.project.* (Svix HMAC)
│   └── EventDispatcher.php
└── VoiceflowService.php            # facade — delegates to subclients, preserves existing public API
```

`VoiceflowService` stays as the public-facing facade so existing callsites keep working; new code can target the typed subclients directly.

## Phased plan

### Phase A — Foundation refactor (1 PR, ~1 day)

**Goal**: Centralize HTTP, add retries everywhere, add structured logging, fix performance regressions, kill dead code. No new API surface.

Tasks:
- Extract `VoiceflowHttpClient` — single factory taking `(host, key, opts)`, returns `PendingRequest` with default `connectTimeout(5)`, `timeout(20)`, `retry(2, 200, ConnectionException, throw: false)`, `withMiddleware(LoggingMiddleware)`.
- Migrate every existing method in `VoiceflowService` to use it; eliminate divergent timeouts and ad-hoc retry logic.
- Add custom exception classes; map upstream HTTP status to typed exceptions in the central client.
- Add Laravel `Log::channel('voiceflow')` calls: request URL, method, status code, latency, agent ID. Never log full body (PII).
- Auto-recover stale `sessionKey`: on 401 from `interact`, `forgetSession()` and retry once.
- Fix `createKbFileDocument` — use `fopen` stream + `is_resource()` check; enforce 10 MB cap inside the service.
- Cache `getVariables` per `(agent, userId)` for 30s — eliminate the per-turn round-trip duplication.
- Fix pagination loops: `searchTranscripts` and `listKbDocuments` accept callback / generator over all pages.
- Delete dead `apiClient()`, `statePath()`, unused `services.voiceflow.api_url`.
- Update error message "Check VOICEFLOW_VERSION_ID" → "Check VOICEFLOW_PROJECT_ID / environment".
- Force per-agent `webhook_secret` regeneration in `RotateWebhookSecret` with min-length 32; deprecate env fallback.

Test additions:
- Retry behavior under flaky upstream (`Http::fake` with sequential 503 → 200)
- Session-key auto-recovery on 401
- Pagination over multiple pages
- Memory-efficient file upload via stream

### Phase B — Tier 1 capability gaps (1-2 PRs, ~2 days)

**Goal**: Close obvious surface holes that the project already needs.

Tasks:
- **Transcripts complete**: `endTranscript()`, `deleteTranscript()` + UI hook in `Conversations/Show.vue` ("end stuck session", "delete for GDPR")
- **Usage API**: `queryUsage(string $metric, array $filters, int $limit)` returning typed `UsageQueryResult`. Wire `messages_last_24h` / `time_saved_hours` from `phase-14-public-stats` to call Voiceflow instead of computing locally (with fallback).
- **KB completeness**: `replaceKbDocument()`, `patchKbDocument()`, `patchKbChunk()`, `uploadKbTable()`
- **Text-paste KB variant**: extend `createKbUrlDocument` to also accept `type=text` (or add `createKbTextDocument`)

Test additions:
- New endpoints (Http::fake + assertSent)
- Stats fall back gracefully when Voiceflow is unconfigured

### Phase C — Streaming chat + inbound webhooks (2-3 PRs, ~3-5 days)

**Goal**: Modern UX + push-not-poll observability.

Tasks:
- **`StreamingClient`** — SSE reader over `/v4/interact/stream`. Emits Trace events as they arrive. Handles `completionEvents=true` for token streaming.
- **Backend streaming endpoint**: `POST /chat/interact/stream` returns `Symfony\Component\HttpFoundation\StreamedResponse` re-emitting Voiceflow's SSE.
- **Frontend integration**: `Chat/Index.vue` switches to `EventSource` when streaming is enabled; falls back to non-stream on unsupported browsers.
- **Session-lifecycle webhook handler**: `POST /api/voiceflow/webhooks/session` accepts `runtime.session.*` and `runtime.call.*`. Auth via shared secret per-project (no HMAC documented). Updates `Conversation.started_at`, `ended_at`, `voiceflow_transcript_id` reactively — kill the polling backfill loop's heavy work.
- **Org-events webhook handler**: `POST /api/voiceflow/webhooks/org` with Svix HMAC verification (add `svix/svix` composer dep). Reactively updates `voiceflow_project_pool` on project deletes; surfaces published-environment events for "agent updated" notifications.

Test additions:
- SSE parsing (mock raw stream)
- Frontend streaming-vs-fallback toggle
- Webhook signature verification (good/bad/missing)
- Idempotency on duplicate events

### Phase D — Evaluations surface (1-2 PRs, ~2 days)

**Goal**: Enable the regression panel referenced in `docs/voiceflow/README.md`.

Tasks:
- Wrap all 8 evaluation endpoints: create, list, get, update, delete, run-sync, batch-queue, estimate
- DTOs: `Evaluation`, `EvaluationResult`, `BatchQueueResult` (handles partial-success `warning.skippedTranscriptIDs`)
- Handle 429 with retry-after from `RateLimitedException`
- New page `Pages/Agents/Evaluations.vue` — list evaluations, run sync against recent transcripts, view results

Test additions:
- Batch partial-success handling (some skipped due to quota)
- 429 honored with retry-after delay

### Phase E — Project / Environment management (1-2 PRs, ~2 days)

**Goal**: Agent backup + traffic split + environment lifecycle.

Tasks:
- Wrap 8 project/environment endpoints
- "Export agent" UI button → calls `exportEnvironmentJson(version: 'published')` → triggers JSON download
- "Clone environment" wizard for staging→production promotion
- Read-only traffic split panel (write is operator-only, behind a flag)
- Acknowledge in docs: project *creation* still requires Voiceflow dashboard (no public API)

Test additions:
- Clone flow with parent environment reference
- Traffic split sums to 100 validation

### Phase F — DX & documentation (1 PR, ~1 day)

**Goal**: Future contributors can wrap a new endpoint without re-deriving the conventions.

Tasks:
- Generate an auto-built coverage table from `VoiceflowService` reflection — write to `docs/voiceflow/coverage.md`, regenerate via `composer hermes-dashboard` (or a dedicated `composer voiceflow:coverage`)
- New phase doc `docs/phase-15-voiceflow-wrapper.md` recording what shipped
- `docs/voiceflow/README.md` updated to reflect "what the codebase uses today" table
- Internal contributor doc `docs/voiceflow/architecture.md` — explains the `Client/`, `Dto/`, `Exceptions/`, `Webhooks/` split

## Test strategy

- **Every new public method gets a feature test** (Http::fake + assertSent + response-shape assertion)
- **Fakes use real Voiceflow response shapes** sourced from `docs/voiceflow/*` examples — no hand-stripped fakes
- **Phase A's retry/auth-recovery/pagination behaviors get dedicated unit tests** (the failure modes that bit us in production)
- **End-to-end paths** (chat launch → interact → lead capture) keep their existing tests; new tests are additive

## Risk register

| Risk | Probability | Impact | Mitigation |
|---|---|---|---|
| Phase A refactor breaks an existing callsite | medium | high | Keep `VoiceflowService` facade signature unchanged; add tests for every existing public method before refactoring |
| Streaming breaks for users behind aggressive proxies | medium | medium | Non-stream fallback always available; user-agent / capability sniff |
| Org-events webhook adds a third-party dep (Svix) | low | low | Optional, lazy-load; failure of Svix lib doesn't block other auth |
| Evaluations + Usage API costs Voiceflow money on tenant side | low | medium | Document in operator-facing copy; add per-agent toggle |
| `getVariables` cache hides real state drift bugs | low | medium | 30s TTL; bypass for diagnostic page; explicit `forgetVariables()` |
| Voiceflow changes a documented endpoint shape | medium | high | DTOs absorb the shape; tests with fixture fakes catch drift |

## Sequencing rationale

Phase A first because every subsequent phase benefits from the centralized client. Tier 1 (Phase B) before streaming (Phase C) because Usage API closes a known fabricated-data wart in `phase-14-public-stats`. Evaluations (D) and Project management (E) are independent and could swap. DX polish (F) last so the auto-generated coverage report has the full final surface to enumerate.

## Definition of done (overall)

- Coverage table (auto-generated) shows ≥95% of `docs/voiceflow/*` endpoints wrapped
- Every wrapper method has at least one feature test
- `composer hermes-fast` passes (PHPStan + tests + lint)
- New phase doc `docs/phase-15-voiceflow-wrapper.md` summarizes what shipped
- Inbound webhook receivers tested against real Voiceflow payloads (recorded fixtures from a sandbox project)
- `data/agents/<each>` populated for any existing agent via one-time `php artisan voiceflow:reconcile` command
- Stats page reads Voiceflow usage API where possible
- README's "What the codebase uses today" table updated

## Open questions for the operator

1. **Streaming priority**: chat streaming is the highest-visibility UX win but biggest implementation cost. Worth it now, or hold for Phase C+?
2. **Evaluations**: is the regression panel actually wanted now, or is "transcripts good enough" the current bar?
3. **Org-events**: enabling Svix-signed webhooks requires configuring them in the Voiceflow dashboard manually (no API). Worth the operational overhead if managed-tier isn't being pursued?
4. **Project-creation gap**: no public `POST /project` exists. Managed-tier still needs the dashboard for provisioning. Confirm this is acceptable for the foreseeable horizon?
5. **Backward-compat scope**: keep `VoiceflowService` facade signature stable, or take the opportunity to break-and-clean? (Recommendation: keep stable through Phase E, deprecate-and-replace in Phase F if needed.)
