---
title: List/Search Knowledge Base Documents
method: GET
path: /v1alpha1/public/knowledge-base/document
host: realtime-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: List KB documents with pagination and optional type filter (ordered by updatedAt desc).
source: https://docs.voiceflow.com/api-reference/kbpublicapidocument/search-documents.md
---

# Search/List Knowledge Base Documents

`GET https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Query parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | string | Page number to retrieve, defaults to 1 (minimum). Order is by date updated, descending. |
| `limit` | string | Documents per page. Default 10, range 1-100. |
| `documentType` | string | Filter by type: `csv`, `pdf`, `text`, `docx`, `table`, `xlsx`, `md`, `url`. |
| `projectEnvironmentIDOrAlias` | string | Environment alias such as `main`. |

## Response schema

```json
{
  "total": 0,
  "data": [
    {
      "documentID": "string",
      "data": {
        "type": "url|pdf|text|docx|md|csv|xlsx|table",
        "name": "string",
        "url": "string|null"
      },
      "updatedAt": "ISO-8601",
      "status": { "type": "ERROR|PENDING|SUCCESS|INITIALIZED" }
    }
  ]
}
```

## Example

```bash
curl -X GET "https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document?page=1&limit=10&documentType=pdf&projectEnvironmentIDOrAlias=main" \
  -H "authorization: YOUR_API_KEY"
```
