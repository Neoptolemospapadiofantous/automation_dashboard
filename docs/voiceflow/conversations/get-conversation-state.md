---
title: Get Conversation State
method: GET
path: /state/user/{userID}
auth: API key (authorization header) + projectID header
summary: Fetch the current conversation state for a user — stack, variables, storage, and turn.
source: https://docs.voiceflow.com/api-reference/state/get-conversation-state.md
---

# Get Conversation State

Retrieve the full state object for a user within a project.

## Endpoint

```
GET https://general-runtime.voiceflow.com/state/user/{userID}
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

## Response — `200`

`Content-Type: application/json`

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

| Field | Type | Description |
|-------|------|-------------|
| `turn` | object | Per-turn data. |
| `stack` | array | Execution stack (active flows). |
| `stack[].nodeID` | string nullable | Current block in the flow. |
| `stack[].diagramID` | string | Required. Flow/diagram ID. |
| `stack[].name` | string nullable | Friendly flow name. |
| `stack[].storage` | object | Required. Flow-scoped storage. |
| `stack[].variables` | object | Required. Flow-scoped variables. |
| `stack[].commands` | array | Flow command list. |
| `stack[].dynamicCommands` | object | Map keyed by command name. |
| `variables` | object | Global variables. |
| `storage` | object | Global storage. |
