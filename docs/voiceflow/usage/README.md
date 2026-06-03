# Voiceflow Usage API

Mirrored from `https://docs.voiceflow.com/api-reference/usage/`.

The usage surface is a single aggregate query endpoint exposed via the analytics API host. Authenticate with a workspace `authorization` header.

| File | Method | Path | Purpose |
|------|--------|------|---------|
| [query-usage.md](./query-usage.md) | POST | `/v2/query/usage` (host: `analytics-api.voiceflow.com`) | Aggregate usage metrics — interactions, intents, users, credit/function/API usage, KB documents, integrations, transcripts, agent usage. |

Note: the `transcripts` query name on `/v2/query/usage` reports aggregate transcript counts. To search or retrieve transcript content, see `../transcripts/`.
