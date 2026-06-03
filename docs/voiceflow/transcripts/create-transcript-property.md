---
title: Create Transcript Property
method: POST
path: /v1/transcript-property
host: analytics-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Define a custom transcript property (typed metadata field) on a project.
source: https://docs.voiceflow.com/api-reference/transcript-property/create-transcript-property.md
---

# Create Transcript Property

`POST https://analytics-api.voiceflow.com/v1/transcript-property`

## Authentication

Header: `authorization: <Voiceflow API key>`

No path or query parameters.

## Request body

```json
{
  "projectID": "string (24 chars, required)",
  "name": "string (1-100 chars, required)",
  "type": "boolean|number|string (required)"
}
```

## Response — 201 Created

```json
{
  "property": {
    "id": "string",
    "projectID": "string",
    "name": "string",
    "type": "string",
    "default": false,
    "createdAt": "date",
    "updatedAt": "date"
  }
}
```

## Example

```bash
curl -X POST https://analytics-api.voiceflow.com/v1/transcript-property \
  -H "authorization: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "projectID": "000000000000000000000001",
    "name": "Customer Sentiment",
    "type": "string"
  }'
```
