---
date: 2026-06-08
type: decision
status: active
tags: [hermes, decisions, voiceflow, streaming, frontend]
---

# Streaming chat uses `fetch + ReadableStream`, not `EventSource`

## Context

The chat page at `resources/js/Pages/Chat/Index.vue` needed a streaming path to consume `POST /chat/interact/stream`. Three browser-native options exist:

1. `EventSource` — built specifically for SSE, automatic reconnect, simple API
2. `fetch + ReadableStream + TextDecoder` — manual SSE parsing, full control
3. WebSocket over Echo's Reverb — bidirectional, message-shaped

## Decision

Use option 2: `fetch().body.getReader()` with manual SSE parsing.

## Rationale

- **EventSource is GET-only** — our endpoint is POST (carries `user_id`, `message`, `lead_id`). EventSource would force querystring tunneling, which both Laravel CSRF tooling and route signing dislike
- **CSRF + cookies "just work"** — `fetch` with `credentials: 'include'` carries cookies + we read the CSRF token from `<meta>` and add it as a header, same as axios does behind the scenes
- **WebSocket is overkill** — chat is request/reply, not bidirectional. Reverb adds operational complexity (server, port, channel auth) for no UX gain
- **Vue rendering is cheap** — appending to a single `reactive` text bubble per stream avoids list re-renders; we don't need a separate streaming framework

## Alternatives rejected

| Option | Why no |
|---|---|
| `EventSource` | GET-only; would force querystring tunneling for the user message |
| WebSocket via Reverb | Adds a server + port + channel auth; chat isn't bidirectional |
| Long-polling | Worse UX than non-streaming axios — defeats the purpose |
| Server-Sent Events via Inertia | Inertia treats responses as full-page swaps; no streaming primitive |

## Consequences

- Older browsers without `ReadableStream` (IE, very old Safari) fall back to the non-streaming `sendBlocking` path automatically — capability detection at `streamSupported`
- Non-stream fallback also kicks in on any non-2xx upstream response, so a misconfigured Voiceflow stream surfaces as the existing axios error UX
- Backend `VoiceflowController::interactStream` emits SSE-shaped frames (`event: trace\ndata: {...}\n\n`) verbatim — matches Voiceflow's native shape, no translation layer
- CSRF read pattern is annotated with `@hermes-keep:` in the source so the inertia-page-scanner doesn't flag it as a Vue reactivity bypass

## Related

- `resources/js/Pages/Chat/Index.vue` — `sendStreaming()` + `handleSseFrame()`
- `app/Http/Controllers/VoiceflowController.php` — `interactStream()`
- `app/Services/Voiceflow/Client/StreamingClient.php` — SSE parser shared with backend tests
