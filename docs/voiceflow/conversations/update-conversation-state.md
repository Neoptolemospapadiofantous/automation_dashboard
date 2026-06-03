---
title: Update Conversation State (replace)
method: PUT
path: /state/user/{userID}
auth: API key (authorization header) + projectID header
summary: Replace the user's full conversation state with the supplied object.
source: https://docs.voiceflow.com/api-reference/state/update-conversation-state.md
---

# Update Conversation State

Replace the user's full state. Use [`PATCH /state/user/{userID}/variables`](./update-conversation-variables.md) for partial variable updates.

## Endpoint

```
PUT https://general-runtime.voiceflow.com/state/user/{userID}
```

## Authentication

| Header | Value |
|--------|-------|
| `authorization` | Voiceflow API key |
| `projectID` | ID of the target Voiceflow project |

## Path parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `userID` | string (max 128) | yes | Unique ID for the user. |

## Request body

`Content-Type: application/json`

**Required:** `stack`, `variables`, `storage`.

```json
{
  "turn": {},
  "stack": [
    {
      "nodeID": "string|null",
      "diagramID": "string",
      "name": "string|null",
      "storage": {},
      "variables": {},
      "commands": [{ "type": "string" }],
      "dynamicCommands": {}
    }
  ],
  "variables": {},
  "storage": {}
}
```

| Field | Type | Notes |
|-------|------|-------|
| `turn` | object | Optional. |
| `stack[]` | array | Required. Each frame must include `diagramID`, `storage`, `variables`. |
| `variables` | object | Required. |
| `storage` | object | Required. |

## Response — `200`

No documented response body.
