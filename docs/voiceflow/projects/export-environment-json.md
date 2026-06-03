---
title: Export Environment JSON
method: GET
path: /v1alpha1/project/{projectID}/environment/{projectEnvironmentAlias}/export-json
auth: Workspace API key (header `authorization`) — security not declared in spec but key is required in practice
summary: Download the full project definition for the environment as a single JSON document, suitable for backup or programmatic analysis.
source: https://docs.voiceflow.com/api-reference/environmentpublicapi/export-environment-json
---

# Export Environment JSON

Base URL: `https://realtime-api.voiceflow.com`

## Path parameters

| Name                     | Type   | Required | Description                              |
| ------------------------ | ------ | -------- | ---------------------------------------- |
| `projectID`              | string | yes      | Project that owns the environment.       |
| `projectEnvironmentAlias`| string | yes      | ID or alias of the environment to export.|

## Query parameters

| Name      | Type   | Required | Default | Description                                            |
| --------- | ------ | -------- | ------- | ------------------------------------------------------ |
| `version` | string | no       | `draft` | Variant to export. Allowed: `draft`, `published`.      |

## Authentication

The OpenAPI spec does **not** declare a security requirement, but in practice the `authorization` API key header is required.

## Response — 200 OK

`AssistantExportData` — a large JSON object describing the entire assistant. Key sections:

- `_version`, `version`, `project`, `assistant`
- `programs`, `diagrams`, `flows`, `agents`, `workflows`
- NLU: `intents`, `utterances`, `requiredEntities`, `responses`, `responseMessages`, `responseDiscriminators`, `entities`, `entityVariants`
- `functions`, `functionPaths`, `functionVariables`
- `events`, `folders`, `prompts`, `promptMessages`
- `apiTools`, `apiToolInputVariables`, `integrationTools`, `mcpIntegrationTools`, `mcpServers`, `mcpServerTools`
- `simulations`, `simulationTurns`, `simulationTurnTests`
- `variables`, `variableStates`, `secrets`
- `attachments`, `cardButtons`, `personas`
- `transcriptEvaluations` — array of evaluation definitions (boolean / number / string / option)
- Knowledge base: `kbDocumentVersions`, `kbSettings`, `kbDocumentMetadataM2M`

## Notes

- This is the closest thing to a "full backup" the public API exposes.
- The dump is large; treat as streaming JSON if you store it.
