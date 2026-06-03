---
title: Delete Transcript Evaluation
method: DELETE
path: /v1/transcript-evaluation/{evaluationID}
auth: Workspace API key (header `authorization`)
summary: Delete an evaluation definition along with every result it produced for transcripts.
source: https://docs.voiceflow.com/api-reference/transcript-evaluation/delete-transcript-evaluation
---

# Delete Transcript Evaluation

Base URL: `https://analytics-api.voiceflow.com`

## Path parameters

| Name           | Type   | Required | Description                                |
| -------------- | ------ | -------- | ------------------------------------------ |
| `evaluationID` | string | yes      | ID of the transcript evaluation to delete. |

## Authentication

API key in `authorization` header.

## Response — 204 No Content

Empty body on success.

## Notes

- Removes the evaluation **and** all stored results for all transcripts. Irreversible.
- No request body.
- No rate limits documented.
