---
title: Start Session (with Traffic Split)
method: POST
path: /v4/project/{projectID}/session
auth: API key (authorization header)
summary: Start a new conversation routed by the project's traffic split, returning a session key for interact calls.
source: https://docs.voiceflow.com/api-reference/session/start-session-with-traffic-split.md
---

# Start Session (with Traffic Split)

Same shape as the environment-specific variant, but the project's configured traffic split decides which environment receives the session.

## Endpoint

```
POST https://general-runtime.voiceflow.com/v4/project/{projectID}/session
```

## Authentication

| Header | Value |
|--------|-------|
| `authorization` | Your Voiceflow project API key |

## Path parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `projectID` | string | yes | ID of the target Voiceflow project. |

## Request body

`Content-Type: application/json`

| Field | Type | Required | Constraints | Description |
|-------|------|----------|-------------|-------------|
| `userID` | string | yes | 1–256 chars | Unique ID for the user. Any existing session for this user ends and is replaced. |

## Response — `200`

```json
{
  "sessionKey": "string"
}
```

| Field | Type | Description |
|-------|------|-------------|
| `sessionKey` | string | Session-scoped authentication key. Pass as the `authorization` header on interact calls. |

## Notes

- Honors traffic-split configuration on the project.
- To force a specific environment instead, use [`start-session-specific-environment`](./start-session-specific-environment.md).
