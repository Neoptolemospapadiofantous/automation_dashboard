---
title: Search Transcripts
method: POST
path: /v1/transcript/project/{projectID}
host: analytics-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Paginated, filterable search of transcripts for a project (by date, session, version, environment).
source: https://docs.voiceflow.com/api-reference/transcript/search-transcripts.md
---

# Search Transcripts

`POST https://analytics-api.voiceflow.com/v1/transcript/project/{projectID}`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Path parameters

- `projectID` (string, required): ID of the target Voiceflow project.

## Query parameters

| Parameter | Type | Default | Range | Description |
|-----------|------|---------|-------|-------------|
| `take` | integer | 25 | 1-100 | Max results to return (pagination). |
| `skip` | integer | 0 | 0-9007199254740991 | Results to skip (pagination). |
| `order` | string | DESC | ASC \| DESC | Result order. |
| `encode` | boolean | — | — | Escape HTML special characters in transcripts. |

## Request body

```json
{
  "filters": [],
  "endDate": "2024-01-31T23:59:59Z",
  "sessionID": "string",
  "startDate": "2024-01-01T00:00:00Z",
  "updatedAfter": "2024-01-01T00:00:00Z",
  "updatedBefore": "2024-01-31T23:59:59Z",
  "versionID": "string",
  "projectEnvironmentIDOrAlias": "string"
}
```

## Response

```json
{
  "transcripts": [
    {
      "id": "string",
      "userID": "string",
      "sessionID": "string",
      "projectID": "string",
      "environmentID": "string",
      "createdAt": "2024-01-31",
      "updatedAt": "2024-01-31",
      "expiresAt": "2024-01-31",
      "endedAt": "2024-01-31",
      "recordingURL": "string",
      "properties": [],
      "evaluations": []
    }
  ]
}
```

## Example

```bash
curl -X POST "https://analytics-api.voiceflow.com/v1/transcript/project/YOUR_PROJECT_ID?take=25&skip=0&order=DESC" \
  -H "authorization: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "startDate": "2024-01-01T00:00:00Z",
    "endDate": "2024-01-31T23:59:59Z"
  }'
```
