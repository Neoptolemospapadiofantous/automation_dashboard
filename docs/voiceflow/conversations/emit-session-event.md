---
title: Emit Session Event
method: POST
path: /v2/project/{projectID}/session/{sessionKey}/event
auth: API key (authorization header)
summary: Push a mid-conversation action/event into an active session that has an open websocket connection.
source: https://docs.voiceflow.com/api-reference/session/emit-session-event.md
---

# Emit Session Event

Send an action (event, intent, text, etc.) into an existing session out of band. Useful for triggering server-side events into a live web-chat session.

## Endpoint

```
POST https://general-runtime.voiceflow.com/v2/project/{projectID}/session/{sessionKey}/event
```

## Authentication

| Header | Value |
|--------|-------|
| `authorization` | Voiceflow API key |

## Path parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `projectID` | string | yes | ID of the target Voiceflow project. |
| `sessionKey` | string | yes | Session key returned by Start Session. Identifies which active session receives the event. |

## Request body

`Content-Type: application/json`

```json
{
  "action": {
    "type": "string",
    "payload": {},
    "diagramID": "string",
    "time": 0,
    "metadata": {}
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `action.type` | string | yes | Action type (e.g. `event`, `intent`, `text`). |
| `action.payload` | object | no | Action-type-specific payload. |
| `action.diagramID` | string | no | Diagram to target. |
| `action.time` | number | no | Timestamp. |
| `action.metadata` | object | no | Free-form metadata. |

## Response — `201`

No response body documented.

## Important

This endpoint only works when the target session has an open websocket connection (e.g. through the Voiceflow web-chat widget or a custom socket integration). Otherwise the event has nowhere to be delivered.
