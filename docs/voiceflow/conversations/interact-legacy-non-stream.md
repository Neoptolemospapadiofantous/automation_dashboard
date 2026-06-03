---
title: Interact - legacy (non-stream)
method: POST
path: /state/user/{userID}/interact
auth: API key (authorization header)
summary: Deprecated legacy interact endpoint keyed by userID. Migrate to POST /v4/interact.
source: https://docs.voiceflow.com/api-reference/conversation/interact--legacy-non-stream.md
---

# Interact - legacy (non-stream)

**Deprecated.** Use [`POST /v4/interact`](./interact-non-stream.md) instead.

## Endpoint

```
POST https://general-runtime.voiceflow.com/state/user/{userID}/interact
```

## Authentication

| Header | Value |
|--------|-------|
| `authorization` | Voiceflow API key |

## Path parameters

| Parameter | Type | Max length | Description |
|-----------|------|------------|-------------|
| `userID` | string | 128 | Unique ID identifying the user in the conversation. |

## Request body

`Content-Type: application/json`

```yaml
state:
  type: object
  variables:
    type: object
    additionalProperties: {}
request: {}
action:
  description: User response (start conversation / advance with text reply, etc.)
config:
  description: Optional settings to configure the response.
```

## Response — `200`

Returns an array of traces in response to the user interaction. Detailed schema not documented; treat as legacy-equivalent of the `/v4/interact` trace array.
