---
title: Interact (stream, SSE)
method: POST
path: /v4/interact/stream
auth: sessionKey (authorization header)
summary: Send an action and receive a Server-Sent Events stream of trace, state, and end events.
source: https://docs.voiceflow.com/api-reference/conversation/interact-stream.md
---

# Interact (stream)

Same semantics as the non-stream endpoint, but the response is delivered as Server-Sent Events so partial output and per-event state can be consumed live.

## Endpoint

```
POST https://general-runtime.voiceflow.com/v4/interact/stream
```

- **Response Content-Type:** `text/event-stream`

## Authentication

| Header | Value |
|--------|-------|
| `authorization` | `sessionKey` from Start Session |

## Request body

```json
{
  "action": { "type": "string" },
  "variables": {},
  "state": {},
  "config": {
    "userTimezone": "America/New_York",
    "completionEvents": true
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `action` | object | yes | One of: `launch`, `text`, `action`, `intent`, `event`, `path`, `no-reply`, `message`, `end`, `dtmf`, `live-agent-handoff`. |
| `variables` | object | no | Key/value pairs merged into session context. |
| `state` | object | no | State override. |
| `config.userTimezone` | string | no | IANA timezone. |
| `config.completionEvents` | boolean | no | If true, LLM output is broken into incremental `completion` traces. See [Completion Events](./completion-events.md). |

## SSE event types

### `trace`
```
event: trace
data: {"type":"text|speak|audio|choice|carousel|...","payload":{...}}
```

### `state`
```
event: state
data: {"turn":{},"stack":[],"storage":{},"variables":{}}
```

### `end`
```
event: end
data: {}
```

### Supported trace types

Text, Speak, Audio, Choice, Carousel, Card, Stream, Block, Debug, DTMF, Entity Filling, Flow, GoTo, Log, No-Reply, Path, Reasoning, Visual, Knowledge Base, Call Forward, Realtime Agent, Live Agent Handoff, Tool Call, Completion.

## Example

```bash
curl -N -X POST https://general-runtime.voiceflow.com/v4/interact/stream \
  -H "authorization: YOUR_SESSION_KEY" \
  -H "Content-Type: application/json" \
  -d '{"action":{"type":"launch"}}'
```

```
event: trace
data: {"type":"speak","payload":{"message":"Hello, how can I help?","type":"message"}}

event: trace
data: {"type":"choice","payload":{"buttons":[{"name":"Option 1"},{"name":"Option 2"}]}}

event: end
data: {}
```
