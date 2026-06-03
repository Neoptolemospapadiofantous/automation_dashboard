---
title: Start Session (Specific Environment)
method: POST
path: /v4/project/{projectID}/environment/{environmentID}/session
auth: API key (authorization header)
summary: Start a new conversation in a specified environment and receive a session key for subsequent interact calls.
source: https://docs.voiceflow.com/api-reference/session/start-session-specific-environment.md
---

# Start Session (Specific Environment)

Start a new conversation against a specific environment of a Voiceflow project. Returns a session-scoped `sessionKey` that authorizes the interact endpoints.

## Endpoint

```
POST https://general-runtime.voiceflow.com/v4/project/{projectID}/environment/{environmentID}/session
```

## Authentication

| Header | Value |
|--------|-------|
| `authorization` | Your Voiceflow project API key |

## Path parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `projectID` | string | yes | ID of the target Voiceflow project (find in agent settings). |
| `environmentID` | string | yes | Alias of the environment to target (e.g. `main`). Find on the environments page. |

## Request body

`Content-Type: application/json`

| Field | Type | Required | Constraints | Description |
|-------|------|----------|-------------|-------------|
| `userID` | string | yes | 1–256 chars | Unique ID for the user. If a session already exists for this user, the existing session ends and a new one is created with a new session key. |

## Response — `200`

```json
{
  "sessionKey": "string"
}
```

| Field | Type | Description |
|-------|------|-------------|
| `sessionKey` | string | Session-scoped authentication key. Pass as the `authorization` header on interact calls. |

## Example

```bash
curl -X POST https://general-runtime.voiceflow.com/v4/project/YOUR_PROJECT_ID/environment/main/session \
  -H "Content-Type: application/json" \
  -H "Authorization: YOUR_API_KEY" \
  -d '{"userID": "user123"}'
```

```json
{
  "sessionKey": "eyJhbGc..."
}
```
