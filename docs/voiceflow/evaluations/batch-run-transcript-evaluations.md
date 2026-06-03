---
title: Batch Run Transcript Evaluations
method: POST
path: /v1/transcript-evaluation/queue
auth: Workspace API key (header `authorization`)
summary: Asynchronously queue a batch of (evaluation × transcript) pairs for processing. Up to 10 evaluations × 100 transcripts per request.
source: https://docs.voiceflow.com/api-reference/transcript-evaluation/batch-run-transcript-evaluations
---

# Batch Run Transcript Evaluations

Base URL: `https://analytics-api.voiceflow.com`

## Request body — `application/json`

| Field            | Type     | Required | Notes                                                  |
| ---------------- | -------- | -------- | ------------------------------------------------------ |
| `projectID`      | string   | yes      | Exactly 24 chars.                                      |
| `evaluationIDs`  | string[] | yes      | 1–10 entries, each exactly 24 chars.                   |
| `transcriptIDs`  | string[] | yes      | 1–100 entries, each exactly 24 chars.                  |

Additional properties are not permitted.

```json
{
  "projectID": "65ff0123456789abcdef0123",
  "evaluationIDs": ["..."],
  "transcriptIDs": ["...", "..."]
}
```

## Response — 201 Created

```json
{
  "transcriptCount": 100,
  "evaluationCount": 3,
  "warning": {
    "type": "quota_exceeded",
    "message": "string",
    "skippedTranscriptIDs": ["..."]
  }
}
```

The `warning` field is present when the per-project queue quota was exceeded and some transcripts were skipped. Without a warning, the entire batch was accepted.

## Response — 429 Too Many Requests

Returned when the project-level queue limit is exceeded entirely. Clients should back off and retry after the indicated delay.

## Behavior notes

- Asynchronous — this enqueues work, it does not return results. Poll the analytics surface (or use webhooks) to retrieve outcomes.
- Per-project queue limits apply.
- Partial success is possible and surfaced via `warning.skippedTranscriptIDs`.

## Authentication

API key in `authorization` header.
