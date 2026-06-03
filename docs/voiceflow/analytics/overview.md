---
title: Analytics API Overview
method: N/A
path: N/A
host: analytics-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Overview of the Voiceflow analytics surface — usage queries, transcripts, transcript properties, and transcript evaluations.
source: https://docs.voiceflow.com/api-reference/analytics-api/overview.md
---

# Analytics API Overview

The analytics surface bundles four endpoint groups, all hosted on `https://analytics-api.voiceflow.com` and authenticated via the `authorization` header containing a Voiceflow workspace API key.

## Groups

1. **Usage** — aggregate metric queries (`/v2/query/usage`). Documented under `../usage/`.
2. **Transcript** — search, retrieve, delete, and end transcripts. Documented under `../transcripts/`.
3. **Transcript Property** — manage custom metadata fields on transcripts. Documented under `../transcripts/`.
4. **Transcript Evaluation** — create, run, and query LLM-based quality assessments. Documented under `../transcripts/`.

The official overview page does not enumerate rate limits or extra headers beyond `authorization` and `Content-Type`. See each endpoint file for the exact method, path, parameters, and schemas.

## Notes

- The transcript-related endpoints all live on the analytics host — they are documented here under `../transcripts/` for ergonomics (matches Voiceflow's own grouping in product UI), but are technically part of the analytics API.
- Usage and analytics queries share the analytics host. The `/v2/query/usage` endpoint accepts a `name` discriminator (`interactions`, `top_intents`, `unique_users`, `credit_usage`, `function_usage`, `api_calls`, `kb_documents`, `integrations`, `transcripts`, `agent_usage`) — see `../usage/query-usage.md`.
