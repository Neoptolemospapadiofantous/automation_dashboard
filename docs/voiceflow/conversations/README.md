---
title: Voiceflow Conversations API (local mirror)
source: https://docs.voiceflow.com/llms.txt
---

# Voiceflow Conversations API

Offline mirror of the Voiceflow Conversations / Session / Interact / State API reference. Each file below is a single endpoint (or background concept) with its request shape, response shape, and an example.

Base URL: `https://general-runtime.voiceflow.com`

## Reading order — a typical conversation lifecycle

| # | Step | File | Method + Path |
|---|------|------|---------------|
| 1 | Read the big picture | [overview.md](./overview.md) | — |
| 2 | Understand the state model | [state-overview.md](./state-overview.md) | — |
| 3 | Start a session (specific env) | [start-session-specific-environment.md](./start-session-specific-environment.md) | `POST /v4/project/{projectID}/environment/{environmentID}/session` |
| 3a | …or via traffic split | [start-session-with-traffic-split.md](./start-session-with-traffic-split.md) | `POST /v4/project/{projectID}/session` |
| 4 | Preload variables (optional) | [update-conversation-variables.md](./update-conversation-variables.md) | `PATCH /state/user/{userID}/variables` |
| 5 | Run a turn (non-stream) | [interact-non-stream.md](./interact-non-stream.md) | `POST /v4/interact` |
| 5a | …or stream the turn (SSE) | [interact-stream.md](./interact-stream.md) | `POST /v4/interact/stream` |
| 5b | …or use a socket connection | [interact-socket.md](./interact-socket.md) | `WS /v4/interact/socket` |
| 5c | Enable token-level streaming | [completion-events.md](./completion-events.md) | query flag on stream |
| 6 | Push a mid-conversation event | [emit-session-event.md](./emit-session-event.md) | `POST /v2/project/{projectID}/session/{sessionKey}/event` |
| 7 | Inspect state | [get-conversation-state.md](./get-conversation-state.md) | `GET /state/user/{userID}` |
| 7a | Replace state | [update-conversation-state.md](./update-conversation-state.md) | `PUT /state/user/{userID}` |
| 8 | Reset / end the user | [delete-conversation-state.md](./delete-conversation-state.md) | `DELETE /state/user/{userID}` |

## Legacy (deprecated — migrate when possible)

| File | Method + Path | Replacement |
|------|---------------|-------------|
| [interact-legacy-non-stream.md](./interact-legacy-non-stream.md) | `POST /state/user/{userID}/interact` | `POST /v4/interact` |
| [interact-legacy-stream.md](./interact-legacy-stream.md) | `POST /v2/project/{projectID}/user/{userID}/interact/stream` | `POST /v4/interact/stream` |

## Fetch status

All pages listed under the Conversations / Sessions / Interact / State categories of `llms.txt` were fetched successfully on this run — no 404s or fetch failures.

## Source

Canonical index: <https://docs.voiceflow.com/llms.txt>
