---
title: Project API Overview
method: —
path: /v1alpha1/project/...
auth: Workspace API key (header `authorization`)
summary: Manage assistant environments (CRUD + clone + publish + export + traffic split) for an existing Voiceflow project. There is no public endpoint to create a project itself.
source: https://docs.voiceflow.com/api-reference/project-api/overview
---

# Project API Overview

Base URL: `https://realtime-api.voiceflow.com`

Stated purpose: "Export your Voiceflow project as a file you can use for programmatic analysis or integration with third-party tooling," plus environment management.

## Endpoint inventory

| Method | Path                                                                                            | File                          |
| ------ | ----------------------------------------------------------------------------------------------- | ----------------------------- |
| GET    | `/v1alpha1/project/{projectID}/environments`                                                    | list-environments.md          |
| GET    | `/v1alpha1/project/{projectID}/environment/{projectEnvironmentIDorAlias}`                       | get-environment.md            |
| POST   | `/v1alpha1/project/{projectID}/environment`                                                     | create-environment.md         |
| DELETE | `/v1alpha1/project/{projectID}/environment/{projectEnvironmentID}`                              | delete-environment.md         |
| POST   | `/v1alpha1/project/{projectID}/environment/{projectEnvironmentIDorAlias}/publish`               | publish-environment.md        |
| GET    | `/v1alpha1/project/{projectID}/environment/{projectEnvironmentAlias}/export-json`               | export-environment-json.md    |
| GET    | `/v1alpha1/project/{projectID}/environment/traffic`                                             | get-traffic-split.md          |
| PATCH  | `/v1alpha1/project/{projectID}/environment/traffic`                                             | update-traffic-split.md       |

## Concepts

- An **environment** is a named, addressable copy of the assistant inside a project. Each env has a `draftVersionID` (the working copy) and a `publishedVersionID` (the snapshot serving live traffic).
- Environments are addressed by **ID or alias** (e.g. `production`, `qa`) on most read endpoints. Delete only accepts the ID.
- **Traffic split** lets you weight requests between environments on a percentage basis (must sum to 100).
- **Publishing** turns the current draft into a new release; releases are immutable snapshots.

## What the API does NOT do

- No `POST /project` (or equivalent) is exposed publicly. There is no documented way to create a Voiceflow project from scratch via API.
- Create Environment can only **clone** an existing environment — there is no way to make an empty one.
- No documented rate limits.
