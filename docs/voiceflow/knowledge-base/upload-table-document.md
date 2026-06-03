---
title: Upload Table Knowledge Base Document
method: POST
path: /v1alpha1/public/knowledge-base/document/upload/table
host: realtime-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Upload a structured table document (JSON rows + schema) into the knowledge base.
source: https://docs.voiceflow.com/api-reference/kbpublicapidocument/upload-table-document.md
---

# Upload Table Document

`POST https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document/upload/table`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Query parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `overwrite` | boolean | If true, overwrite existing table with the same name. |
| `markdownConversion` | boolean | Auto-convert HTML to markdown for better chunks. |
| `llmBasedChunks` | boolean | Use LLM-based chunking strategy. |
| `llmGeneratedQ` | boolean | LLM generates a question per chunk, prepended to chunk. |
| `llmContentSummarization` | boolean | LLM summarizes/rewrites content (limit 15 rows per upload). |
| `llmPrependContext` | boolean | LLM generates a context summary prepended to each chunk. |

## Request body

```json
{
  "data": {
    "name": "string (required)",
    "schema": {
      "searchableFields": ["string"],
      "metadataFields": ["string"]
    },
    "items": [{ "customField": "value" }],
    "folderID": "string",
    "url": "string",
    "documentMetadata": [
      { "key": "string (max 255)", "values": ["string"] }
    ],
    "projectEnvironmentIDOrAlias": "string"
  }
}
```

Required: `data.name`, `data.schema.searchableFields`, `data.items`.

## Response

```json
{
  "chunks": [
    { "chunkID": "string", "content": "string", "metadata": {} }
  ],
  "data": {
    "documentID": "string",
    "data": {
      "type": "table",
      "name": "string",
      "rowsCount": 0,
      "url": "string|null"
    },
    "updatedAt": "ISO-8601",
    "status": { "type": "ERROR|PENDING|SUCCESS|INITIALIZED" }
  },
  "metadata": [
    { "key": "string", "values": ["string"] }
  ]
}
```

## Example

```bash
curl -X POST https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document/upload/table \
  -H "authorization: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "data": {
      "name": "product_catalog",
      "schema": {
        "searchableFields": ["name", "description"],
        "metadataFields": ["category"]
      },
      "items": [
        { "name": "Product A", "description": "High quality item", "price": 99.99 }
      ],
      "projectEnvironmentIDOrAlias": "main"
    }
  }'
```
