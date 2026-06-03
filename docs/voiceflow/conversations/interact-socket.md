---
title: Interact (socket)
method: WS
path: /v4/interact/socket
auth: API key in client.start payload (optional for public clients) + sessionKey for reconnections
summary: Persistent socket.io connection for bidirectional, real-time agent interactions including reconnectable sessions.
source: https://docs.voiceflow.com/api-reference/conversations-api/interact-socket.md
---

# Interact (socket)

Socket.io connection (with WebSocket / polling / WebTransport fallbacks) for real-time conversational AI. Sessions persist independently of socket connections, so clients can reconnect and resume.

## Connection

- **Base URL:** `https://general-runtime.voiceflow.com`
- **Path:** `/v4/interact/socket`
- **Protocol:** socket.io

## Authentication

Authentication is performed via the `client.start` event payload — it may include an optional `authorization` field (Voiceflow project API key, found under settings). Omit this for public-facing clients.

## Lifecycle

1. socket.io connects.
2. Client emits `client.start` with project metadata and (optionally) a previously issued session key.
3. Server responds with `client.started`. If a new session is needed, client emits `session.create` and waits for `session.created` (which returns a JWT `sessionKey`).
4. For each turn: client emits `action.send` -> receives `action.status` (`accepted`/`rejected`) -> receives one or more `action.trace` events -> receives `action.status: completed`.

## Sending an action

```ts
socket.emit('action.send', {
  action: {
    type: 'text', // or 'launch' | 'event' | 'intent' | ...
    payload: 'Hello, how are you?'
  }
});
```

### Initial launch

```ts
socket.emit('action.send', {
  action: { type: 'launch', payload: {} }
});
```

## Server events

| Event | Purpose |
|-------|---------|
| `client.started` | Confirms client handshake; indicates if a new session is needed. |
| `session.created` | Returns JWT `sessionKey` (usable for reconnects). |
| `action.status` | Action lifecycle: `accepted`, `rejected`, `completed`. |
| `action.trace` | Agent responses (speech, text, audio, choice, etc.). |
| `session.ended` | Conversation terminated with reason. |
| `server.restart` | Graceful shutdown; reconnect required. |

## Config options (`client.start.config`)

- `completionEvents` (boolean) — stream LLM output as live chunks.
- `userTimezone` (string) — IANA timezone.
- `audioEvents` (boolean) — enable TTS audio responses.
- `audioEncoding` (string) — e.g. `audio/x-mulaw`, `audio/pcm`.

## Reconnection

Persist `sessionKey` from `session.created`. When reconnecting, include it in `client.start` to resume the same conversation state without creating a new session.
