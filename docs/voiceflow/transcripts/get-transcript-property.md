---
title: Get Transcript Property
method: GET
path: /v1/transcript-property/{propertyID}
host: analytics-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Fetch a single transcript property definition by ID.
source: https://docs.voiceflow.com/api-reference/transcript-property/get-transcript-property.md
---

# Get Transcript Property

`GET https://analytics-api.voiceflow.com/v1/transcript-property/{propertyID}`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Path parameters

- `propertyID` (string, required): ID of the property to target.

## Response

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
curl -X GET "https://analytics-api.voiceflow.com/v1/transcript-property/PROPERTY_ID" \
  -H "authorization: YOUR_API_KEY"
```
