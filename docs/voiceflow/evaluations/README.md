# Voiceflow Evaluations API (mirror)

Local mirror of the **Transcript Evaluation API** reference pages.

Base URL: `https://analytics-api.voiceflow.com`
All endpoints authenticate with a workspace API key in the `authorization` header.

## Files

| File                                       | Method | Path                                                             | Purpose                                                              |
| ------------------------------------------ | ------ | ---------------------------------------------------------------- | -------------------------------------------------------------------- |
| `create-transcript-evaluation.md`          | POST   | `/v1/transcript-evaluation`                                      | Create a new evaluation definition (boolean / number / string / option). |
| `get-all-evaluations.md`                   | GET    | `/v1/transcript-evaluation/project/{projectID}`                  | List all evaluations for a project.                                  |
| `get-transcript-evaluation.md`             | GET    | `/v1/transcript-evaluation/{evaluationID}`                       | Get one evaluation definition.                                       |
| `update-transcript-evaluation.md`          | PATCH  | `/v1/transcript-evaluation/{evaluationID}`                       | Update prompt/settings/options.                                      |
| `delete-transcript-evaluation.md`          | DELETE | `/v1/transcript-evaluation/{evaluationID}`                       | Delete an evaluation and all its results.                            |
| `run-transcript-evaluation.md`             | POST   | `/v1/transcript-evaluation/{evaluationID}/transcript/{transcriptID}` | Synchronously run one eval against one transcript.                 |
| `batch-run-transcript-evaluations.md`      | POST   | `/v1/transcript-evaluation/queue`                                | Async queue: up to 10 evals × 100 transcripts per call.              |
| `estimate-transcript-evaluation.md`        | POST   | `/v1/transcript-evaluation/estimate`                             | Pre-flight cost estimation for a filtered transcript set.            |

## Key shapes

- **Four evaluation types** discriminated by `type`: `boolean` (with `true/falsePrompt`), `number` (with `minimum/maximumValue` + `minimum/maximumPrompt`), `string` (free-form), `option` (with 1–20 options).
- **Settings** are shared across all four: `{ model, realtime { voice, eagerness }, maxTokens, temperature, reasoningEffort }`. The `model` enum lists 60+ values (Claude, GPT, Gemini, Llama, Voiceflow proprietary).
- **Project IDs** are always exactly 24 chars; same for evaluation and transcript IDs in this surface.

## Notes

- The `queue` (batch) endpoint is the only one that documents a rate-limit response (HTTP 429 with a backoff hint) and a partial-success warning (`quota_exceeded` skips).
- All endpoints succeeded with 200 — none of the eight pages failed to fetch.
