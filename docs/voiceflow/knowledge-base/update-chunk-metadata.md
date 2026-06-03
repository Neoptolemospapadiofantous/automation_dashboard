---
title: Update KB Chunk Metadata
method: PATCH
path: /v1alpha1/public/knowledge-base/document/{documentID}/chunk/{chunkID}
host: realtime-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Patch the metadata on a specific KB document chunk (used for chunk-level filtering in queries).
source: https://docs.voiceflow.com/api-reference/kbpublicapidocument/update-chunk-metadata-by-chunk-id.md
---

# Update KB Chunk Metadata

`PATCH https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document/{documentID}/chunk/{chunkID}`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Path parameters

- `documentID` (string, required): ID of the document to target.
- `chunkID` (string, required): ID of the document chunk to target.

## Request body

```json
{
  "data": {
    "metadata": {
      "key": "value"
    }
  }
}
```

The `metadata` object accepts additional properties of any type; values are usable as filters in KB API queries.

## Response — 200 OK

```json
{
  "data": {
    "documentID": "string",
    "data": {
      "type": "pdf|url|docx|text|md|csv|xlsx|table",
      "name": "string",
      "url": "string"
    },
    "updatedAt": "2024-01-01T00:00:00Z",
    "status": { "type": "SUCCESS|ERROR|PENDING|INITIALIZED" }
  }
}
```

## Example

```bash
curl -X PATCH https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document/doc123/chunk/chunk456 \
  -H "authorization: your-api-key" \
  -H "Content-Type: application/json" \
  -d '{"data":{"metadata":{"category":"support","priority":"high"}}}'
```
