---
title: End Transcript
method: POST
path: /v1/transcript/{transcriptID}/project/{projectID}/end
host: analytics-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Mark a transcript as ended for the given project.
source: https://docs.voiceflow.com/api-reference/transcript/end-transcript.md
---

# End Transcript

`POST https://analytics-api.voiceflow.com/v1/transcript/{transcriptID}/project/{projectID}/end`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Path parameters

- `transcriptID` (string, required): ID of the transcript to target.
- `projectID` (string, required): ID of the target Voiceflow project.

## Response

`200 OK` with empty body.

## Example

```bash
curl -X POST https://analytics-api.voiceflow.com/v1/transcript/TRANSCRIPT_ID/project/PROJECT_ID/end \
  -H "authorization: YOUR_API_KEY"
```
