---
title: Get All Transcript Properties
method: GET
path: /v1/transcript-property/project/{projectID}
host: analytics-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: List all transcript property definitions for a project.
source: https://docs.voiceflow.com/api-reference/transcript-property/get-all-transcript-properties.md
---

# Get All Transcript Properties

`GET https://analytics-api.voiceflow.com/v1/transcript-property/project/{projectID}`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Path parameters

- `projectID` (string, required): ID of the target Voiceflow project.

## Response

```json
{
  "properties": [
    {
      "id": "string",
      "projectID": "string",
      "name": "string",
      "type": "string",
      "default": false,
      "createdAt": "date",
      "updatedAt": "date"
    }
  ]
}
```

## Example

```bash
curl -X GET "https://analytics-api.voiceflow.com/v1/transcript-property/project/YOUR_PROJECT_ID" \
  -H "authorization: YOUR_API_KEY"
```
