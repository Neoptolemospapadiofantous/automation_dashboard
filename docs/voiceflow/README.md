# Voiceflow API reference (mirrored)

Local copy of every Voiceflow REST API page we care about, organized by
surface. Mirrored once on **2026-06-03** from `docs.voiceflow.com/llms.txt`
(the canonical machine-readable index) so the codebase has an offline,
diff-able reference that doesn't shift under us mid-implementation.

## What's covered

| Surface | Folder | Files | Host | Auth |
|---|---|---|---|---|
| **Conversations** (V4) | [conversations/](./conversations/) | 16 | `general-runtime.voiceflow.com` | sessionKey (per-conv) + DM key (to start) |
| **Knowledge Base** | [knowledge-base/](./knowledge-base/) | 11 | `realtime-api.voiceflow.com` + `general-runtime.voiceflow.com` (query) | Workspace key |
| **Transcripts** | [transcripts/](./transcripts/) | 13 | `analytics-api.voiceflow.com` | Workspace key |
| **Evaluations** (LLM judges over transcripts) | [evaluations/](./evaluations/) | 9 | `analytics-api.voiceflow.com` | Workspace key |
| **Analytics** | [analytics/](./analytics/) | 2 | `analytics-api.voiceflow.com` | Workspace key |
| **Usage** | [usage/](./usage/) | 2 | `analytics-api.voiceflow.com` | Workspace key |
| **Project / Environments** | [projects/](./projects/) | 10 | `api.voiceflow.com` | Workspace key |
| **Webhooks** (inbound from Voiceflow) | [webhooks/](./webhooks/) | 3 | (configured per subscription) | HMAC signature |

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
| Conversations streaming (SSE) | ❌ not used | candidate for token-by-token chat UX |
| Project / Environments | ❌ not used | see "managed-tier" note below |
| Evaluations | ❌ not used | candidate for "is the agent regressing?" panel |
| Usage API | ❌ not used | candidate for the stats page |
| Inbound webhooks (session-lifecycle, org-events) | ❌ not used | candidate for replacing some polling |

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

This was discussed in the parent multi-tenancy doc — see
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
