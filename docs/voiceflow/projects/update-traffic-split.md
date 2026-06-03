---
title: Update Traffic Split
method: PATCH
path: /v1alpha1/project/{projectID}/environment/traffic
auth: Workspace API key (header `authorization`)
summary: Replace the traffic-split configuration for the project. Percentages must sum to 100.
source: https://docs.voiceflow.com/api-reference/environmentpublicapi/update-traffic-split
---

# Update Traffic Split

Base URL: `https://realtime-api.voiceflow.com`

## Path parameters

| Name        | Type   | Required | Description                                  |
| ----------- | ------ | -------- | -------------------------------------------- |
| `projectID` | string | yes      | ID of the project that owns the environments.|

## Request body — `application/json`

Free-form object where each key is an environment ID **or alias**, and each value is a number 0–100.

```json
{
  "production": 80,
  "qa": 20
}
```

Constraints:
- Each value must be between 0 and 100 (inclusive).
- All values **must sum to exactly 100**.

## Response — 200 OK

Returns the updated split, keyed by environment ID:

```json
{
  "data": {
    "65ff...": 80,
    "66aa...": 20
  }
}
```

## Authentication

API key in `authorization` header.
