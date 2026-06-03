---
title: Replace Knowledge Base Document
method: PUT
path: /v1alpha1/public/knowledge-base/document/{documentID}
host: realtime-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Replace a KB document's content via JSON URL/text or multipart file upload.
source: https://docs.voiceflow.com/api-reference/kbpublicapidocument/replace-document.md
---

# Replace Knowledge Base Document

`PUT https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document/{documentID}`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Path parameters

- `documentID` (string, required): ID of the document to target.

## Query parameters

- `maxChunkSize` (number, optional): token range 500-1500, default 1000. Smaller chunk size means narrower context, faster response, less tokens consumed.

## Request body — `application/json`

```json
{
  "data": {
    "type": "url",
    "url": "string",
    "name": "string",
    "refreshRate": "daily|weekly|monthly|never",
    "folderID": "string",
    "documentMetadata": [
      { "key": "string", "values": ["string"] }
    ],
    "metadata": {},
    "projectEnvironmentIDOrAlias": "string"
  }
}
```

## Request body — `multipart/form-data`

- `file` (binary, required) — max 10 MB
- `folderID` (string, optional)
- `documentMetadata` (JSON string, optional)
- `metadata` (JSON string, optional)
- `url` (string, optional)
- `projectEnvironmentIDOrAlias` (string, optional)

## Response — 201 Created

```json
{
  "data": {
    "documentID": "string",
    "data": {},
    "updatedAt": "2024-01-01T00:00:00Z",
    "status": { "type": "ERROR|PENDING|SUCCESS|INITIALIZED" }
  }
}
```

## Example

```bash
curl -X PUT https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document/DOC123 \
  -H "authorization: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "data": {
      "type": "url",
      "url": "https://example.com/doc",
      "name": "Example Doc",
      "refreshRate": "weekly"
    }
  }'
```
