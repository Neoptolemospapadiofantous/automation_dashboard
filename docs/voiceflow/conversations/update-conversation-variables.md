---
title: Update Conversation Variables (merge)
method: PATCH
path: /state/user/{userID}/variables
auth: API key (authorization header) + projectID header
summary: Merge the supplied key/value pairs into the user's state variables without replacing the whole state.
source: https://docs.voiceflow.com/api-reference/state/update-conversation-variables.md
---

# Update Conversation Variables

Partial update — merges the request body into the user's variables. Other state fields (stack, storage, turn) are preserved.

## Endpoint

```
PATCH https://general-runtime.voiceflow.com/state/user/{userID}/variables
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

Free-form object of key/value pairs:

```json
{
  "user_name": "John",
  "account_tier": "premium",
  "last_order_id": "ord_123"
}
```

## Response — `200`

`Content-Type: application/json`

Returns the merged state:

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
