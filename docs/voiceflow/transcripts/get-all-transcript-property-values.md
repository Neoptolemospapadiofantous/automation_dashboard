---
title: Get All Transcript Property Values
method: GET
path: /v1/transcript-property-value/transcript/{transcriptID}
host: analytics-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: List all property values set on a given transcript.
source: https://docs.voiceflow.com/api-reference/transcript-property-value/get-all-transcript-property-values.md
---

# Get All Transcript Property Values

`GET https://analytics-api.voiceflow.com/v1/transcript-property-value/transcript/{transcriptID}`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Path parameters

- `transcriptID` (string, required): ID of the transcript to target.

## Response

```json
{
  "propertyValues": [
    {
      "propertyID": "string",
      "transcriptID": "string",
      "value": "string",
      "metadata": {},
      "createdAt": "date",
      "updatedAt": "date"
    }
  ]
}
```

`metadata` may be `null`.

## Example

```bash
curl -X GET \
  "https://analytics-api.voiceflow.com/v1/transcript-property-value/transcript/your-transcript-id" \
  -H "authorization: YOUR_API_KEY"
```
