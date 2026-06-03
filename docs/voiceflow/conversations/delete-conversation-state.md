---
title: Delete Conversation State
method: DELETE
path: /state/user/{userID}
auth: API key (authorization header) + projectID header
summary: Clear all conversation state and session data for a user within a project.
source: https://docs.voiceflow.com/api-reference/state/delete-conversation-state.md
---

# Delete Conversation State

Remove all conversation state and session info for a specific user. Effectively resets the user.

## Endpoint

```
DELETE https://general-runtime.voiceflow.com/state/user/{userID}
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

No documented response body.
