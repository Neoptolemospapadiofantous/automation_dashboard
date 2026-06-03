---
title: Knowledge Base Query / Answer
method: POST
path: /knowledge-base/query
host: general-runtime.voiceflow.com
auth: Workspace API key (authorization header)
summary: Run a KB query against a project, optionally synthesizing an LLM answer over matching chunks.
source: https://docs.voiceflow.com/api-reference/public-docs/query.md
---

# Knowledge Base Query

`POST https://general-runtime.voiceflow.com/knowledge-base/query`

## Authentication

Header: `authorization: <Voiceflow API key>`

No path or query parameters — all inputs go in the request body.

## Request body

```json
{
  "question": "string (required)",
  "projectID": "string (optional)",
  "instruction": "string (optional)",
  "chunkLimit": 5,
  "synthesis": true,
  "settings": {
    "model": "string",
    "temperature": 0.7,
    "maxTokens": 0,
    "system": "string",
    "reasoningEffort": "string|null"
  },
  "filters": {},
  "internalFilters": [
    {
      "key": "string",
      "value": "string",
      "operator": "is|is_not|contains|not_contains"
    }
  ],
  "projectEnvironmentIDOrAlias": "string",
  "versionVariant": "draft|published"
}
```

- `chunkLimit`: integer 1-30.
- `versionVariant`: defaults to `published`.

## Response

```json
{
  "type": "completion",
  "model": "string",
  "output": "string|null",
  "duration": 0,
  "tokens": 0,
  "queryTokens": 0,
  "answerTokens": 0,
  "cacheWriteTokens": 0,
  "queryCachedTokens": 0,
  "queryRemainderTokens": 0,
  "inputMultiplier": 0,
  "cacheMultiplier": 0,
  "outputMultiplier": 0,
  "cacheWriteMultiplier": 0,
  "base": {
    "queryTokens": 0,
    "answerTokens": 0,
    "cacheWriteTokens": 0,
    "queryCachedTokens": 0
  },
  "chunks": [
    {
      "score": 0,
      "chunkID": "string",
      "documentID": "string",
      "content": "string",
      "source": {},
      "metadata": {},
      "internalMetadata": [
        { "key": "string", "values": ["string"] }
      ]
    }
  ]
}
```

## Example

```bash
curl -X POST https://general-runtime.voiceflow.com/knowledge-base/query \
  -H "authorization: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "question": "What is the refund policy?",
    "projectID": "your-project-id",
    "synthesis": true,
    "chunkLimit": 5
  }'
```
