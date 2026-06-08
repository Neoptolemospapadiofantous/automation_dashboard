# Voiceflow API reference (mirrored)

Local copy of every Voiceflow REST API page we care about, organized by
surface. Mirrored once on **2026-06-03** from `docs.voiceflow.com/llms.txt`
(the canonical machine-readable index) so the codebase has an offline,
diff-able reference that doesn't shift under us mid-implementation.

## What's covered

| Surface | Folder | Files | Host | Auth |
|---|---|---|---|---|
| **[[docs/voiceflow/conversations/README|Conversations** (V4)]] | [conversations/](./conversations/) | 16 | `general-runtime.voiceflow.com` | sessionKey (per-conv) + DM key (to start) |
| **[[docs/voiceflow/knowledge-base/README|Knowledge Base**]] | [knowledge-base/](./knowledge-base/) | 11 | `realtime-api.voiceflow.com` + `general-runtime.voiceflow.com` (query) | Workspace key |
| **[[docs/voiceflow/transcripts/README|Transcripts**]] | [transcripts/](./transcripts/) | 13 | `analytics-api.voiceflow.com` | Workspace key |
| **[[docs/voiceflow/evaluations/README|Evaluations** (LLM judges over transcripts)]] | [evaluations/](./evaluations/) | 9 | `analytics-api.voiceflow.com` | Workspace key |
| **Analytics** | [analytics/](./analytics/) | 2 | `analytics-api.voiceflow.com` | Workspace key |
| **Usage** | [usage/](./usage/) | 2 | `analytics-api.voiceflow.com` | Workspace key |
| **[[docs/voiceflow/projects/README|Project / Environments**]] | [projects/](./projects/) | 10 | `api.voiceflow.com` | Workspace key |
| **[[docs/voiceflow/webhooks/README|Webhooks** (inbound from Voiceflow)]] | [webhooks/](./webhooks/) | 3 | (configured per subscription) | HMAC signature |

74 files total. Each endpoint page has frontmatter (`title`, `method`,
`path`, `auth`, `summary`, `source`) so they're greppable and the source
URL is one click away.

## What the codebase uses today

| Surface | Status | Where |
|---|---|---|
| Conversations (V4: session + interact + state) | ✅ in use | `app/Services/VoiceflowService.php` |
| Knowledge Base (list/create/query) | ✅ in use | `app/Http/Controllers/KnowledgeBaseController.php` |
| Transcripts (search + get) | ✅ in use | `voiceflow:backfill` artisan command |
| Per-agent webhook (us → us) | ✅ in use | `app/Http/Controllers/VoiceflowWebhookController.php` (this is OUR webhook for Voiceflow Custom Actions; not the inbound ones below) |
| Conversations streaming (SSE) | ✅ in use | `StreamingClient` + `VoiceflowController::interactStream` (Phase 15); `Chat/Index.vue` uses fetch+ReadableStream with non-stream fallback |
| Project / Environments | ✅ in use | `RealtimeClient::{list,get,clone,delete,publish,exportEnvironmentJson,getTrafficSplit,setTrafficSplit}Environment(s)`; UI at `Pages/Agents/Environments.vue`. Still no public `POST /project` — see "managed-tier" note below |
| Evaluations | ✅ in use | `AnalyticsClient::{create,list,get,update,delete,run,queueBatch,estimate}Evaluation`; UI at `Pages/Agents/Evaluations.vue` |
| [[docs/voiceflow/usage/README|Usage API]] | ✅ in use | `AnalyticsClient::queryUsage` + `VoiceflowService::safeUsageCount` (per-agent stats panel still pending — wrapper ready) |
| Inbound webhooks (session-lifecycle, org-events) | ✅ in use | `Voiceflow\SessionLifecycleController` (per-agent secret) + `Voiceflow\OrgEventsController` (Svix HMAC, falls back to platform shared secret); persists to `voiceflow_webhook_events` |

## Critical finding: managed-tier provisioning

The Project API does **not** expose project creation. The most you can
do programmatically is **clone an existing environment** within a
project (`POST /v1alpha1/project/{projectID}/environment` with a
`cloneFromEnvironmentID`). There's no `POST /project` in the public
API surface — see [projects/README.md](./projects/README.md) for the
full analysis.

Implication for the SaaS direction:
- **BYOK** (current architecture — user pastes their own Voiceflow keys)
  is the path of least resistance and the data model supports it cleanly.
- **Managed** would require either:
  - A private/partner provisioning API from Voiceflow sales
  - One shared template project with per-customer environments + traffic
    split (weaker isolation, shared rate limits)
  - Manual project creation as part of onboarding

This was discussed in the [[phase-13-multitenancy|parent multi-tenancy doc]] — see
[`../phase-13-multitenancy.md`](../phase-13-multitenancy.md).

## Refreshing the mirror

The Voiceflow docs evolve. To re-mirror:

1. Re-fetch `https://docs.voiceflow.com/llms.txt` and diff against the
   files here — any new endpoint URL is new surface we should pull down.
2. Re-fetch the individual reference pages whose endpoints are still in
   use by the app, to catch silent schema/parameter drift.

There's no automation in-repo for this yet; if drift becomes a
problem, the right answer is probably a `php artisan voiceflow:docs:sync`
command that does both steps.

## Host map (cheat sheet)

| Host | Used for |
|---|---|
| `general-runtime.voiceflow.com` | Conversations (V4 session + interact + state), KB query |
| `realtime-api.voiceflow.com` | KB document CRUD + search + upload |
| `analytics-api.voiceflow.com` | Transcripts, transcript properties, evaluations, analytics, usage |
| `api.voiceflow.com` | Project / environments |
| (your subscription target) | Inbound webhooks |
