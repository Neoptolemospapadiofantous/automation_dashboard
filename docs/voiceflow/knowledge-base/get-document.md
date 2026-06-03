---
title: Get Knowledge Base Document
method: GET
path: /v1alpha1/public/knowledge-base/document/{documentID}
host: realtime-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Retrieve a single KB document by ID, including chunks and metadata.
source: https://docs.voiceflow.com/api-reference/kbpublicapidocument/get-document.md
---

# Get Knowledge Base Document

`GET https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document/{documentID}`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Path parameters

- `documentID` (string, required): ID of the document to target.

## Query parameters

- `projectEnvironmentIDOrAlias` (string, optional): environment alias such as `main`.

## Response schema (`DocumentFindOnePublicResponse`)

- `chunks` — array of `KBDocumentChunk` objects: `chunkID`, `content`, `metadata`.
- `data` — document metadata: `documentID`, type-specific data, `updatedAt`, `status`.
- `metadata` — array of `DocumentMetadataFields` (key + values).

## Example

```bash
curl -X GET \
  "https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document/DOC_123?projectEnvironmentIDOrAlias=main" \
  -H "authorization: YOUR_API_KEY"
```

```json
{
  "chunks": [
    { "chunkID": "chunk_1", "content": "Sample content", "metadata": {} }
  ],
  "data": {
    "documentID": "DOC_123",
    "data": { "type": "pdf", "name": "document.pdf", "url": "https://..." },
    "updatedAt": "2024-01-15T10:30:00Z",
    "status": { "type": "SUCCESS", "data": {} }
  },
  "metadata": [
    { "key": "category", "values": ["technical"] }
  ]
}
```
