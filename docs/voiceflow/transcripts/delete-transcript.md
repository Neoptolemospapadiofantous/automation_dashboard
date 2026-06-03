---
title: Delete Transcript
method: DELETE
path: /v1/transcript/{transcriptID}
host: analytics-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Delete a transcript by ID.
source: https://docs.voiceflow.com/api-reference/transcript/delete-transcript.md
---

# Delete Transcript

`DELETE https://analytics-api.voiceflow.com/v1/transcript/{transcriptID}`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Path parameters

- `transcriptID` (string, required): ID of the transcript to target.

## Response

`204 No Content`.

## Example

```bash
curl -X DELETE \
  https://analytics-api.voiceflow.com/v1/transcript/YOUR_TRANSCRIPT_ID \
  -H "authorization: YOUR_API_KEY"
```
