---
title: Get All Evaluations
method: GET
path: /v1/transcript-evaluation/project/{projectID}
auth: Workspace API key (header `authorization`)
summary: List every evaluation defined under a project.
source: https://docs.voiceflow.com/api-reference/transcript-evaluation/get-all-evaluations
---

# Get All Evaluations

Base URL: `https://analytics-api.voiceflow.com`

## Path parameters

| Name        | Type   | Required | Description                       |
| ----------- | ------ | -------- | --------------------------------- |
| `projectID` | string | yes      | ID of the target Voiceflow project. |

## Authentication

API key in `authorization` header.

## Response — 200 OK

```json
{
  "evaluations": [
    {
      "id": "string",
      "projectID": "string",
      "name": "string",
      "description": null,
      "default": true,
      "enabled": true,
      "averageCost": null,
      "prompt": "string",
      "settings": null,
      "systemTag": null,
      "type": "boolean|number|string|option",
      "truePrompt": "string",
      "falsePrompt": "string"
    }
  ]
}
```

Each element is one of `BooleanTranscriptEvaluation`, `NumberTranscriptEvaluation`, `StringTranscriptEvaluation`, or `OptionTranscriptEvaluation` (polymorphic via `oneOf`, with the type-specific fields documented in `create-transcript-evaluation.md`).

## Rate limits

Not specified.
