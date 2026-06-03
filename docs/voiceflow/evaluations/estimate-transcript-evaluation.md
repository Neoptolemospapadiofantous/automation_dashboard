---
title: Estimate Transcript Evaluation Cost
method: POST
path: /v1/transcript-evaluation/estimate
auth: Workspace API key (header `authorization`)
summary: Estimate the total cost of running one or more evaluations against a filtered set of transcripts before actually queuing them.
source: https://docs.voiceflow.com/api-reference/transcript-evaluation/estimate-transcript-evaluation
---

# Estimate Transcript Evaluation Cost

Base URL: `https://analytics-api.voiceflow.com`

## Request body — `application/json`

| Field                          | Type                | Required | Notes                                                              |
| ------------------------------ | ------------------- | -------- | ------------------------------------------------------------------ |
| `evaluationIDs`                | string[]            | yes      | Each 24 chars. Min 1 item.                                         |
| `filters`                      | TranscriptFilter[]  | no       | Up to 50 transcript property filters.                              |
| `startDate`                    | ISO 8601            | no       | Transcripts started at/after this datetime.                        |
| `endDate`                      | ISO 8601            | no       | Transcripts started at/before this datetime.                       |
| `updatedAfter`                 | ISO 8601            | no       | Transcripts updated at/after this datetime.                        |
| `updatedBefore`                | ISO 8601            | no       | Transcripts updated at/before this datetime.                       |
| `sessionID`                    | string              | no       | Restrict to a specific session.                                    |
| `versionID`                    | string              | no       | Version identifier.                                                |
| `projectEnvironmentIDOrAlias`  | string              | no       | Restrict to a project environment.                                 |
| `environmentID`                | string \| string[]  | no       | **Deprecated** — use `versionID` instead.                          |

### Filter operators

`gt`, `gte`, `lt`, `lte` (numeric), `eq`, `neq` (string / number / boolean), `between` (`[low, high]`), `in`, `nin` (arrays), `contains` (string), `exists`, `not_exists`.

## Response — 200 OK

```json
{
  "totalCost": 15.5,
  "breakdown": {
    "transcriptCount": 42,
    "evaluations": [
      { "id": "eval-id-1", "cost": 7.75 },
      { "id": "eval-id-2", "cost": 7.75 }
    ]
  }
}
```

- `totalCost` — aggregate across all evaluations.
- `breakdown.transcriptCount` — number of transcripts matched by the filters.
- `breakdown.evaluations` — per-evaluation cost split.

## Authentication

API key in `authorization` header.

## Rate limits

Not specified.
