---
title: Query Usage Metrics
method: POST
path: /v2/query/usage
host: analytics-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Run an aggregate usage query (interactions, top intents, unique users, credit usage, etc.) for a project.
source: https://docs.voiceflow.com/api-reference/usage/query-usage.md
---

# Query Usage

`POST https://analytics-api.voiceflow.com/v2/query/usage`

## Authentication

Header: `authorization: <Voiceflow API key>`

No path or query parameters — all inputs go in the request body.

## Request body

```json
{
  "data": {
    "name": "interactions|top_intents|unique_users|credit_usage|function_usage|api_calls|kb_documents|integrations|transcripts|agent_usage",
    "filter": {
      "projectID": "string (required, minLength 1)",
      "startTime": "ISO-8601",
      "endTime": "ISO-8601",
      "projectEnvironmentIDOrAlias": "string|string[]",
      "limit": 100,
      "cursor": 0
    }
  }
}
```

- `filter.limit`: integer, default 100, range 1-500.

## Response

An object with a `result` field shaped per query type:

- **List format** (most queries): `{ "cursor": 0, "items": [] }`
- **Intent format** (`top_intents`): `{ "intents": [] }`

Each item/intent contains period, `projectID`, `environmentID`, `count`, and type-specific fields.

## Example

```bash
curl -X POST https://analytics-api.voiceflow.com/v2/query/usage \
  -H "authorization: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "data": {
      "name": "interactions",
      "filter": {
        "projectID": "your-project-id",
        "startTime": "2024-01-01T00:00:00Z",
        "endTime": "2024-01-31T23:59:59Z"
      }
    }
  }'
```
