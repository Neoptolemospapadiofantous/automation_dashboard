---
title: Get Traffic Split
method: GET
path: /v1alpha1/project/{projectID}/environment/traffic
auth: Workspace API key (header `authorization`)
summary: Return the current traffic-split configuration for the project, keyed by environment ID.
source: https://docs.voiceflow.com/api-reference/environmentpublicapi/get-traffic-split
---

# Get Traffic Split

Base URL: `https://realtime-api.voiceflow.com`

## Path parameters

| Name        | Type   | Required | Description                                  |
| ----------- | ------ | -------- | -------------------------------------------- |
| `projectID` | string | yes      | ID of the project that owns the environments.|

## Authentication

API key in `authorization` header.

## Response — 200 OK

```json
{
  "data": {
    "<environmentID-A>": 70,
    "<environmentID-B>": 30
  }
}
```

Each value is a number between 0 and 100; the values across all environments sum to 100.
