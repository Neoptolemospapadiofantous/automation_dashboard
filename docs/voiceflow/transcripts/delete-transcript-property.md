---
title: Delete Transcript Property
method: DELETE
path: /v1/transcript-property/{propertyID}
host: analytics-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Delete a transcript property definition by ID.
source: https://docs.voiceflow.com/api-reference/transcript-property/delete-transcript-property.md
---

# Delete Transcript Property

`DELETE https://analytics-api.voiceflow.com/v1/transcript-property/{propertyID}`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Path parameters

- `propertyID` (string, required): ID of the property to target.

## Response

`204 No Content`.

## Example

```bash
curl -X DELETE https://analytics-api.voiceflow.com/v1/transcript-property/{propertyID} \
  -H "authorization: YOUR_API_KEY"
```
