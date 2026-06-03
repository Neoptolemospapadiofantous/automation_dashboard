---
title: Update KB Document Metadata
method: PATCH
path: /v1alpha1/public/knowledge-base/document/{documentID}
host: realtime-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Patch a document's metadata (filterable key/value tags plus freeform metadata).
source: https://docs.voiceflow.com/api-reference/kbpublicapidocument/update-document-metadata.md
---

# Update KB Document Metadata

`PATCH https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document/{documentID}`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Path parameters

- `documentID` (string, required): ID of the document to target.

## Request body

```json
{
  "data": {
    "documentMetadata": [
      {
        "key": "string (max 255 chars)",
        "values": ["string"]
      }
    ],
    "metadata": {
      "additionalProperties": {}
    },
    "projectEnvironmentIDOrAlias": "string"
  }
}
```

## Response — 200 OK

```json
{
  "data": {
    "documentID": "string",
    "data": {},
    "updatedAt": "2024-01-01T00:00:00Z",
    "status": {
      "type": "SUCCESS|ERROR|PENDING|INITIALIZED",
      "data": {}
    }
  }
}
```

## Example

```bash
curl -X PATCH https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document/doc123 \
  -H "authorization: your-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "data": {
      "documentMetadata": [
        { "key": "category", "values": ["sales", "support"] }
      ]
    }
  }'
```
