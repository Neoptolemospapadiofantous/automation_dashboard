---
title: Delete a Project Environment
method: DELETE
path: /v1alpha1/project/{projectID}/environment/{projectEnvironmentID}
auth: Workspace API key (header `authorization`)
summary: Permanently delete an environment from a project.
source: https://docs.voiceflow.com/api-reference/environmentpublicapi/delete-a-project-environment
---

# Delete a Project Environment

Base URL: `https://realtime-api.voiceflow.com`

## Path parameters

| Name                   | Type   | Required | Description                |
| ---------------------- | ------ | -------- | -------------------------- |
| `projectID`            | string | yes      | Project that owns the env. |
| `projectEnvironmentID` | string | yes      | Environment to delete.     |

Note: this endpoint takes the environment **ID only**, not an alias (unlike Get/Publish/Export which accept either).

## Authentication

API key in `authorization` header.

## Response — 204 No Content

Empty body on success.

## Notes

- Operation ID: `ProjectEnvironmentApiPublicHTTPController_deleteOne`
- Tags: `EnvironmentPublicApi`, `Public-Docs`
- The main environment for a project is likely not deletable (not explicitly stated in the public docs).
