---
title: Interact - legacy (stream, SSE)
method: POST
path: /v2/project/{projectID}/user/{userID}/interact/stream
auth: API key (authorization header)
summary: Deprecated legacy streaming interact endpoint keyed by projectID + userID. Migrate to POST /v4/interact/stream.
source: https://docs.voiceflow.com/api-reference/conversation/interact--legacy-stream.md
---

# Interact - legacy (stream)

**Deprecated.** Use [`POST /v4/interact/stream`](./interact-stream.md) instead. Returns SSE trace events followed by an end event.

## Endpoint

```
POST https://general-runtime.voiceflow.com/v2/project/{projectID}/user/{userID}/interact/stream
```

## Authentication

| Header | Value |
|--------|-------|
| `authorization` | Voiceflow API key |

## Path parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `projectID` | string | yes | Project ID. |
| `userID` | string | yes | Unique user ID. |

## Query parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `modality` | string | — | `voice`, `chat`, or `api`. |
| `environment` | string | `development` | Which environment to target. |
| `audio_events` | boolean | — | Enables TTS audio traces. |
| `audio_encoding` | string | `audio/mp3` | `audio/mp3`, `audio/x-mulaw`, or `audio/pcm`. |
| `completion_events` | boolean | — | Stream LLM traces as incremental chunks. |
| `userTimezone` | string | — | IANA timezone for this conversation. |
| `versionVariant` | string | — | Version variant. |
| `state` | boolean | — | Include conversation state events between traces. |

## Request body

`Content-Type: application/json`

```json
{
  "action": {
    "type": "launch|text|action|intent|event|path|no-reply|message|end|dtmf|live-agent-handoff|general",
    "payload": {},
    "diagramID": "string",
    "time": 0,
    "metadata": {}
  },
  "variables": {},
  "sessionID": "string"
}
```

**Required:** `action` (nullable allowed).

## SSE response

Response `Content-Type: text/event-stream`.

### Trace event
```json
{
  "id": "string",
  "type": "event",
  "event": "trace",
  "data": {
    "type": "audio|speak|stream|block|cardV2|carousel|channel-action|choice|completion|...",
    "payload": {}
  }
}
```

Available trace types include: AudioTrace, SpeakTrace, StreamTrace, BlockTrace, CardTrace, CarouselTrace, ChoiceTrace, CompletionTrace, DebugTrace, TextTrace, ReasoningTrace, and others.

### State event (when `state=true`)
```json
{
  "id": "string",
  "type": "event",
  "event": "state",
  "data": {
    "turn": {},
    "stack": [
      {
        "name": "string",
        "nodeID": "string",
        "diagramID": "string",
        "storage": {},
        "commands": [],
        "dynamicCommands": {},
        "variables": {}
      }
    ],
    "storage": {},
    "variables": {}
  }
}
```

### End event
```json
{
  "id": "string",
  "type": "event",
  "event": "end",
  "data": {}
}
```

## Status

- `200` — successful stream response.

## Deprecation

Endpoint will continue to operate but a removal timeline will be announced. Migrate to the v4 endpoint at your earliest convenience.
