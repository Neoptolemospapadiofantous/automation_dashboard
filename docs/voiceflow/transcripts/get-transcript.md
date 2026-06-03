---
title: Get Transcript
method: GET
path: /v1/transcript/{transcriptID}
host: analytics-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Fetch a single transcript including logs, history, properties, and evaluations.
source: https://docs.voiceflow.com/api-reference/transcript/get-transcript.md
---

# Get Transcript

`GET https://analytics-api.voiceflow.com/v1/transcript/{transcriptID}`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Path parameters

- `transcriptID` (string, required): ID of the transcript to target.

## Query parameters

- `unredacted` (boolean, optional): when enabled, un-redacted logs are returned if still available.
- `filterConversation` (boolean, optional): when enabled, only `text`, `speak`, and `live-agent-handoff` traces are returned.
- `customTraceTypes` (string[], optional): extra trace types to include when `filterConversation` is enabled.
- `encode` (boolean, optional): escape HTML special characters in transcripts.

## Response

```json
{
  "transcript": {
    "id": "string",
    "userID": "string",
    "sessionID": "string",
    "projectID": "string",
    "environmentID": "string",
    "createdAt": "2024-01-01",
    "updatedAt": "2024-01-02",
    "expiresAt": "2024-02-01",
    "endedAt": "2024-01-02",
    "recordingURL": "https://...",
    "properties": [],
    "evaluations": [],
    "history": [],
    "logs": []
  }
}
```

## Example

```bash
curl -X GET "https://analytics-api.voiceflow.com/v1/transcript/abc123?unredacted=true&filterConversation=false" \
  -H "authorization: your-api-key"
```
