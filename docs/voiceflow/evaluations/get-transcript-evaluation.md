---
title: Get Transcript Evaluation
method: GET
path: /v1/transcript-evaluation/{evaluationID}
auth: Workspace API key (header `authorization`)
summary: Get the full definition of a single evaluation.
source: https://docs.voiceflow.com/api-reference/transcript-evaluation/get-transcript-evaluation
---

# Get Transcript Evaluation

Base URL: `https://analytics-api.voiceflow.com`

## Path parameters

| Name           | Type   | Required | Description                              |
| -------------- | ------ | -------- | ---------------------------------------- |
| `evaluationID` | string | yes      | ID of the transcript evaluation to fetch. |

## Authentication

API key in `authorization` header.

## Response — 200 OK

```json
{
  "evaluation": { /* see create-transcript-evaluation.md for the full shape */ }
}
```

The returned `evaluation` is polymorphic — one of `BooleanTranscriptEvaluation`, `NumberTranscriptEvaluation`, `StringTranscriptEvaluation`, or `OptionTranscriptEvaluation`.

Common fields: `id`, `projectID`, `name`, `description`, `default`, `enabled`, `averageCost`, `prompt`, `settings`, `systemTag`, `type`.

Settings sub-object: `model`, `realtime { voice, eagerness }`, `maxTokens`, `temperature`, `reasoningEffort`.

## Rate limits

Not specified.
