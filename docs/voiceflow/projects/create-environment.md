---
title: Create Environment (clone)
method: POST
path: /v1alpha1/project/{projectID}/environment
auth: Workspace API key (header `authorization`)
summary: Create a new environment for an existing project by cloning another environment (defaults to the assistant's main environment).
source: https://docs.voiceflow.com/api-reference/environmentpublicapi/create-environment
---

# Create Environment

Base URL: `https://realtime-api.voiceflow.com`

This endpoint creates an additional environment **inside an existing project**. It does **not** create a project from scratch — see the projects/README.md "Managed SaaS tier verdict" section.

## Path parameters

| Name        | Type   | Required | Description                                  |
| ----------- | ------ | -------- | -------------------------------------------- |
| `projectID` | string | yes      | ID of the project that owns the environments. |

## Request body — `application/json`

`CreateProjectEnvironmentApiPublicRequest`:

| Field                    | Type   | Required | Notes                                                                     |
| ------------------------ | ------ | -------- | ------------------------------------------------------------------------- |
| `name`                   | string | yes      | Min length 1.                                                             |
| `alias`                  | string | no       | Min length 1. Auto-generated from `name` if omitted.                      |
| `cloneFromEnvironmentID` | string | no       | Source environment to clone. If omitted, the project's main env is used.  |

```json
{
  "name": "QA",
  "alias": "qa",
  "cloneFromEnvironmentID": "65f0..."
}
```

## Response — 201 Created

Returns a `ProjectEnvironmentResponse` with the same shape as Get Environment.

## Authentication

API key in `authorization` header. Standard workspace key is sufficient.

## Caveats

- **Clone-only.** There is no way to create an empty environment with no underlying content; you must clone from an existing one.
- The new env starts at `trafficPercentage = 0` (you must subsequently call Update Traffic Split to send traffic to it).
- No rate limits documented.
