# Voiceflow Transcripts API

Mirrored from `https://docs.voiceflow.com/api-reference/transcript/`, `transcript-property/`, `transcript-property-value/`, and `transcript-evaluation/`.

All endpoints are hosted on `https://analytics-api.voiceflow.com` and authenticate via the `authorization` header with a Voiceflow workspace API key.

## Transcripts

| File | Method | Path | Purpose |
|------|--------|------|---------|
| [search-transcripts.md](./search-transcripts.md) | POST | `/v1/transcript/project/{projectID}` | Paginated/filtered transcript search. |
| [get-transcript.md](./get-transcript.md) | GET | `/v1/transcript/{transcriptID}` | Fetch one transcript with logs, history, props, evals. |
| [end-transcript.md](./end-transcript.md) | POST | `/v1/transcript/{transcriptID}/project/{projectID}/end` | Mark a transcript as ended. |
| [delete-transcript.md](./delete-transcript.md) | DELETE | `/v1/transcript/{transcriptID}` | Delete a transcript. |

## Transcript properties (definitions)

| File | Method | Path | Purpose |
|------|--------|------|---------|
| [create-transcript-property.md](./create-transcript-property.md) | POST | `/v1/transcript-property` | Define a typed custom property on a project. |
| [get-transcript-property.md](./get-transcript-property.md) | GET | `/v1/transcript-property/{propertyID}` | Get one property definition. |
| [get-all-transcript-properties.md](./get-all-transcript-properties.md) | GET | `/v1/transcript-property/project/{projectID}` | List all property definitions on a project. |
| [update-transcript-property.md](./update-transcript-property.md) | PATCH | `/v1/transcript-property/{propertyID}` | Rename / retype a property. |
| [delete-transcript-property.md](./delete-transcript-property.md) | DELETE | `/v1/transcript-property/{propertyID}` | Delete a property definition. |

## Transcript property values

| File | Method | Path | Purpose |
|------|--------|------|---------|
| [set-transcript-property-value.md](./set-transcript-property-value.md) | POST | `/v1/transcript-property-value` | Set a property value on a transcript. |
| [get-all-transcript-property-values.md](./get-all-transcript-property-values.md) | GET | `/v1/transcript-property-value/transcript/{transcriptID}` | List property values on a transcript. |
| [delete-transcript-property-value.md](./delete-transcript-property-value.md) | DELETE | `/v1/transcript-property-value/transcript/{transcriptID}/property/{propertyID}` | Remove a property value from a transcript. |

## Transcript evaluations (LLM judges)

The evaluation endpoints (`/v1/transcript-evaluation/*`) are filed under
**[[docs/voiceflow/evaluations/README|[../evaluations/](../evaluations/README.md)]]** — they share a host with
transcripts but are a distinct API surface (define an evaluation once,
run it against many transcripts).
