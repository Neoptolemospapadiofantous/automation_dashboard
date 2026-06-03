---
title: Delete Transcript Property Value
method: DELETE
path: /v1/transcript-property-value/transcript/{transcriptID}/property/{propertyID}
host: analytics-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Remove a property value from a specific transcript.
source: https://docs.voiceflow.com/api-reference/transcript-property-value/delete-transcript-property-value.md
---

# Delete Transcript Property Value

`DELETE https://analytics-api.voiceflow.com/v1/transcript-property-value/transcript/{transcriptID}/property/{propertyID}`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Path parameters

- `transcriptID` (string, required): ID of the transcript to target.
- `propertyID` (string, required): ID of the property to target.

## Response

`204 No Content`.

## Example

```bash
curl -X DELETE \
  https://analytics-api.voiceflow.com/v1/transcript-property-value/transcript/abc123/property/xyz789 \
  -H "authorization: YOUR_VOICEFLOW_API_KEY"
```
