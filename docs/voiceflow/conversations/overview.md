---
title: Conversations API Overview
method: N/A
path: N/A
auth: API key
summary: Runtime HTTP interface to run conversation turns, send events, and read or modify session state for Voiceflow agents.
source: https://docs.voiceflow.com/api-reference/conversations-api/overview.md
---

# Conversations API Overview

A runtime interface to programmatically interact with Voiceflow agents over HTTP, enabling custom user interfaces and session management.

## Base URL

```
https://general-runtime.voiceflow.com
```

## What you can do

- Run conversation turns via `interact` (non-stream and stream)
- Emit mid-conversation `session` events
- Read, replace, delete, or merge `state` for a user
- Start sessions either against a specific environment or via the project's traffic split

## Endpoint categories

**Conversation**
- `POST /v4/interact` — non-stream
- `POST /v4/interact/stream` — SSE
- `WS  /v4/interact/socket` — socket.io
- Legacy: `POST /state/user/{userID}/interact`, `POST /v2/project/{projectID}/user/{userID}/interact/stream`

**Session**
- `POST /v4/project/{projectID}/environment/{environmentID}/session` — start in specific environment
- `POST /v4/project/{projectID}/session` — start with traffic split
- `POST /v2/project/{projectID}/session/{sessionKey}/event` — emit session event

**State**
- `GET    /state/user/{userID}` — fetch state
- `PUT    /state/user/{userID}` — replace state
- `DELETE /state/user/{userID}` — clear state
- `PATCH  /state/user/{userID}/variables` — merge variables

## Request / response pattern

A client sends an **action** (text, launch, intent, button, etc.) and the runtime returns one or more **traces** describing the agent's response.

```json
{
  "action": {
    "type": "text",
    "payload": "I need help with my order"
  }
}
```

## Session model

- State persists per `userID` across turns.
- Variables can be preloaded (user name, account tier, etc.) before a turn.
- `environment` parameter targets a specific project environment (default: `main`).
