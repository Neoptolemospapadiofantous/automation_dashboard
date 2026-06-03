---
title: Set Transcript Property Value
method: POST
path: /v1/transcript-property-value
host: analytics-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Set the value of a transcript property for a specific transcript.
source: https://docs.voiceflow.com/api-reference/transcript-property-value/set-transcript-property-value.md
---

# Set Transcript Property Value

`POST https://analytics-api.voiceflow.com/v1/transcript-property-value`

## Authentication

Header: `authorization: <Voiceflow API key>`

No path or query parameters.

## Request body

```json
{
  "propertyID": "string (required)",
  "transcriptID": "string (required)",
  "value": "string (required)",
  "metadata": {}
}
```

`metadata` is optional and nullable.

## Response — 201 Created

```json
{
  "propertyValue": {
    "propertyID": "string",
    "transcriptID": "string",
    "value": "string",
    "metadata": {},
    "createdAt": "date",
    "updatedAt": "date"
  }
}
```

## Example

```bash
curl -X POST https://analytics-api.voiceflow.com/v1/transcript-property-value \
  -H "authorization: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "propertyID": "prop_123",
    "transcriptID": "trans_456",
    "value": "example_value",
    "metadata": {"key": "value"}
  }'
```
