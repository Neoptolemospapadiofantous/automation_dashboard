---
title: Update Transcript Property
method: PATCH
path: /v1/transcript-property/{propertyID}
host: analytics-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Rename or retype a transcript property definition.
source: https://docs.voiceflow.com/api-reference/transcript-property/update-transcript-property.md
---

# Update Transcript Property

`PATCH https://analytics-api.voiceflow.com/v1/transcript-property/{propertyID}`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Path parameters

- `propertyID` (string, required): ID of the property to target.

## Request body

```json
{
  "name": "string (1-100 chars)",
  "type": "boolean|number|string"
}
```

## Response

`204 No Content`.

## Example

```bash
curl -X PATCH https://analytics-api.voiceflow.com/v1/transcript-property/property123 \
  -H "authorization: your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "User Sentiment",
    "type": "string"
  }'
```
