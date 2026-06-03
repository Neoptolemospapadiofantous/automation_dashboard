---
title: State Overview
method: N/A
path: /state/user/{userID}
auth: API key + projectID header
summary: Model for per-user dialog state — stack of active flows plus variables, storage, and turn data.
source: https://docs.voiceflow.com/api-reference/conversations-api/state-overview.md
---

# State Overview

Voiceflow keys dialog state by `userID` — any string that uniquely identifies a session participant (e.g. `user54646`, `user@gmail.com`, `1-647-424-4242`).

## Shape

**Stack** — array of currently active flows. Each entry:

| Field | Description |
|-------|-------------|
| `programID` | Flow identifier (from the creator URL). |
| `nodeID` | Current block position within the flow. |
| `variables` | Flow-scoped variables. |
| `storage` | Internal runtime parameters. |
| `commands` | Flow commands. |

The first stack entry's `programID` always matches the agent's `versionID`.

**Variables** — populated through two paths:

1. **Entities** — values supplied by the user (via utterances).
2. **Variables** — assigned by design logic (API call blocks, custom code, etc.).

### Example variables

```json
{
  "type": "pepperoni",
  "size": "large",
  "amount": 6,
  "sessions": 0,
  "user_id": "1234",
  "timestamp": 1645112935,
  "intent_confidence": 100,
  "last_utterance": "large"
}
```

## Related state endpoints

- [`GET /state/user/{userID}`](./get-conversation-state.md)
- [`PUT /state/user/{userID}`](./update-conversation-state.md)
- [`DELETE /state/user/{userID}`](./delete-conversation-state.md)
- [`PATCH /state/user/{userID}/variables`](./update-conversation-variables.md)
