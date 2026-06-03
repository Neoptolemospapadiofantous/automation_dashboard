---
title: Create Transcript Evaluation
method: POST
path: /v1/transcript-evaluation
auth: Workspace API key (header `authorization`)
summary: Define a new transcript evaluation (boolean / number / string / option) for a project.
source: https://docs.voiceflow.com/api-reference/transcript-evaluation/create-transcript-evaluation
---

# Create Transcript Evaluation

Base URL: `https://analytics-api.voiceflow.com`

Creates a new evaluation definition that will be run against transcripts.

## Request body — `application/json`

The body is a `oneOf` over four shapes, discriminated by `type`. All shapes share these common fields:

| Field         | Type           | Required | Notes                                                                |
| ------------- | -------------- | -------- | -------------------------------------------------------------------- |
| `projectID`   | string         | yes      | Exactly 24 characters.                                               |
| `name`        | string         | yes      | 1–100 characters.                                                    |
| `description` | string \| null | no       | Up to 250 characters.                                                |
| `enabled`     | boolean        | yes      | When true, the eval runs on every new transcript automatically.      |
| `prompt`      | string         | yes      | 1–10000 characters. Describes what the LLM should evaluate.          |
| `settings`    | object         | yes      | `TranscriptEvaluationSettings` — see below.                          |
| `type`        | enum           | yes      | `boolean` \| `number` \| `string` \| `option`.                       |

### `TranscriptEvaluationSettings`

```json
{
  "model": "gpt-4o | claude-4.5-sonnet | gemini-2.5-flash | ...",
  "realtime": { "voice": "string", "eagerness": "string" },
  "maxTokens": 0,
  "temperature": 0,
  "reasoningEffort": "minimal | low | medium | high | null"
}
```

The `model` enum lists 60+ values across OpenAI, Anthropic, Google, Meta, and proprietary Voiceflow models.

### Type-specific fields

**`boolean`**
```json
{ "type": "boolean", "truePrompt": "1-10000 chars", "falsePrompt": "1-10000 chars" }
```

**`number`**
```json
{
  "type": "number",
  "minimumValue": 1, "minimumPrompt": "1-10000 chars",
  "maximumValue": 5, "maximumPrompt": "1-10000 chars"
}
```

**`string`** — no extra fields beyond the common set.

**`option`**
```json
{
  "type": "option",
  "options": [
    { "value": "string (1-100)", "prompt": "string (1-10000)", "included": true, "color": "string" }
  ]
}
```

`options` requires 1–20 items.

## Response — 201 Created

```json
{
  "evaluation": {
    "id": "string",
    "projectID": "string",
    "name": "string",
    "description": "string|null",
    "default": false,
    "enabled": true,
    "averageCost": null,
    "prompt": "string",
    "settings": { /* TranscriptEvaluationSettings */ },
    "systemTag": null,
    "type": "boolean|number|string|option",
    "truePrompt":   "...only on boolean",
    "falsePrompt":  "...only on boolean",
    "minimumValue": 0,
    "minimumPrompt":"...only on number",
    "maximumValue": 0,
    "maximumPrompt":"...only on number",
    "options": [ /* only on option */ ]
  }
}
```

## Authentication

API key in `authorization` header.

## Rate limits

Not specified.
