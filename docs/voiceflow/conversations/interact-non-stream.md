---
title: Interact (non-stream)
method: POST
path: /v4/interact
auth: sessionKey (authorization header)
summary: Send a single action and receive a JSON array of traces describing the agent's response for that turn.
source: https://docs.voiceflow.com/api-reference/conversation/interact-non-stream.md
---

# Interact (non-stream)

Send one user action and receive an array of traces in one JSON response.

## Endpoint

```
POST https://general-runtime.voiceflow.com/v4/interact
```

## Authentication

| Header | Value |
|--------|-------|
| `authorization` | `sessionKey` returned by the Start Session endpoint |

## Request body

`Content-Type: application/json`

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `action` | AnyRequest | yes | The user's action for this turn. |
| `variables` | object | no | Key/value pairs merged into session variables before processing. |
| `state` | object | no | State management object. |
| `config` | object | no | Optional turn settings. |
| `config.userTimezone` | string | no | IANA timezone (e.g. `America/New_York`) used for date/time localization. |

### Action types (`AnyRequest`)

- `launch` — start a new conversation
- `text` — send a user message (`payload: string`)
- `action` — self-describing operation with payload
- `intent` — trigger a specific intent with entities
- `event` — event with payload
- `path` — continue down a specified path
- `no-reply` — signal user non-response
- `message` — similar to `text`
- `end` — indicate end of conversation
- `dtmf` — phone dial-tone input
- `live-agent-handoff` — interact with a live agent

## Response — `200`

```json
{
  "traces": [
    {
      "type": "string",
      "payload": {},
      "paths": [],
      "defaultPath": 0,
      "time": 0,
      "turnID": "string",
      "handleID": "string"
    }
  ]
}
```

### Common trace types

`text`, `speak`, `audio`, `choice`, `card`, `carousel`, `completion`, `debug`, `flow`, `knowledge-base`, `live-agent-handoff`, `tool-call`, `block`, `path`, `visual`, `reasoning`, `no-reply`, `entity-filling`, `goto`, `log`, `stream`, `dtmf`, `call-forward`, `realtime-agent`.

## Examples

```bash
curl -X POST https://general-runtime.voiceflow.com/v4/interact \
  -H "Authorization: YOUR_SESSION_KEY" \
  -H "Content-Type: application/json" \
  -d '{"action":{"type":"launch"}}'
```

```json
{
  "action": {
    "type": "text",
    "payload": "Hello, I need help with my account"
  },
  "variables": {
    "user_name": "John",
    "account_tier": "premium"
  },
  "config": {
    "userTimezone": "America/New_York"
  }
}
```

```json
{
  "traces": [
    {
      "type": "text",
      "payload": {
        "message": "Hello John! How can I assist you today?",
        "slate": { "id": "text-1", "content": [] },
        "messageID": "msg-123"
      },
      "turnID": "turn-1",
      "time": 1234567890
    },
    {
      "type": "choice",
      "payload": {
        "buttons": [
          {
            "name": "Account Settings",
            "request": { "type": "action", "payload": {} }
          }
        ],
        "messageID": "choice-1"
      },
      "turnID": "turn-1"
    }
  ]
}
```
