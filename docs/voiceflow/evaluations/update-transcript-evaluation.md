---
title: Update Transcript Evaluation
method: PATCH
path: /v1/transcript-evaluation/{evaluationID}
auth: Workspace API key (header `authorization`)
summary: Update an existing evaluation's prompt, settings, or type-specific options.
source: https://docs.voiceflow.com/api-reference/transcript-evaluation/update-transcript-evaluation
---

# Update Transcript Evaluation

Base URL: `https://analytics-api.voiceflow.com`

## Path parameters

| Name           | Type   | Required | Description                                |
| -------------- | ------ | -------- | ------------------------------------------ |
| `evaluationID` | string | yes      | ID of the transcript evaluation to update. |

## Request body — `application/json`

`oneOf` over four variants, matching the same shape as Create:

Common fields:
- `name` (string, 1–100)
- `description` (string \| null, ≤250)
- `enabled` (boolean) — when true, runs on every new transcript
- `prompt` (string, 1–10000)
- `settings` (object \| null) — model, temperature, maxTokens, reasoningEffort

Type-specific:
- **Boolean** — `truePrompt`, `falsePrompt` (1–10000 each)
- **Number / Scale** — `minimumPrompt`, `maximumPrompt` (1–10000 each)
- **String** — no extra fields
- **Option / Multiple Choice** — `options` (1–20 items: `value`, `prompt`, `included`, `color`)

## Response — 204 No Content

Empty body on success.

## Authentication

API key in `authorization` header.

## Available models

60+ entries spanning Claude, GPT, Gemini, Llama and Voiceflow-proprietary models.

## Rate limits

Not specified.
