---
title: Run Transcript Evaluation (single)
method: POST
path: /v1/transcript-evaluation/{evaluationID}/transcript/{transcriptID}
auth: Workspace API key (header `authorization`)
summary: Synchronously run one evaluation against one transcript and return the verdict + reasoning + cost.
source: https://docs.voiceflow.com/api-reference/transcript-evaluation/run-transcript-evaluation
---

# Run Transcript Evaluation (single)

Base URL: `https://analytics-api.voiceflow.com`

## Path parameters

| Name           | Type   | Required | Description                                 |
| -------------- | ------ | -------- | ------------------------------------------- |
| `evaluationID` | string | yes      | Evaluation definition to execute.           |
| `transcriptID` | string | yes      | Transcript to evaluate.                     |

## Request body — `application/json`

```json
{
  "projectID": "string (exactly 24 chars)"
}
```

Additional properties are **not** permitted.

## Response — 201 Created

```json
{
  "result": {
    "transcriptID": "string",
    "evaluationID": "string",
    "value": "number | string | boolean",
    "reason": "string",
    "cost": 0.0
  }
}
```

`value` is typed according to the evaluation's `type` field (boolean / number / string / option-value-string).

## Authentication

API key in `authorization` header.

## Rate limits

Not specified.
