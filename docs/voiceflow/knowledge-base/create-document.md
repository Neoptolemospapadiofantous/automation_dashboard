---
title: Create Knowledge Base Document
method: POST
path: /v1alpha1/public/knowledge-base/document
host: realtime-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Create a KB document from a URL or uploaded file (PDF/DOCX/CSV/XLSX/text/md).
source: https://docs.voiceflow.com/api-reference/kbpublicapidocument/create-document.md
---

# Create Knowledge Base Document

`POST https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Query parameters

- `maxChunkSize` (number, optional): token range 500-1500, default 1000.
- `overwrite` (boolean, optional): replace existing document with same name.
- `markdownConversion` (boolean, optional): convert HTML to markdown.
- `llmBasedChunks` (boolean, optional): enable LLM-based chunking.
- `llmGeneratedQ` (boolean, optional): generate questions per chunk and prepend.
- `llmContentSummarization` (boolean, optional): summarize/rewrite content.
- `llmPrependContext` (boolean, optional): prepend a context summary to each chunk.

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
    "data": {
      "type": "url|docx|pdf|text|md|csv|xlsx",
      "name": "string",
      "url": "string",
      "refreshRate": "daily|weekly|monthly|never",
      "rowsCount": "number"
    },
    "updatedAt": "2024-01-01T00:00:00Z",
    "status": { "type": "ERROR|PENDING|SUCCESS|INITIALIZED" }
  }
}
```

## Examples

```bash
curl -X POST https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document \
  -H "authorization: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "data": {
      "type": "url",
      "url": "https://example.com/docs",
      "name": "My Document",
      "refreshRate": "weekly"
    }
  }'
```

```bash
curl -X POST https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document \
  -H "authorization: YOUR_API_KEY" \
  -F "file=@document.pdf" \
  -F "folderID=folder123"
```
