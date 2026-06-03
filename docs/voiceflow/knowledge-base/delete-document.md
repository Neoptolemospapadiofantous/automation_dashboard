---
title: Delete Knowledge Base Document
method: DELETE
path: /v1alpha1/public/knowledge-base/document/{documentID}
host: realtime-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Delete a single KB document by ID.
source: https://docs.voiceflow.com/api-reference/kbpublicapidocument/delete-document.md
---

# Delete Knowledge Base Document

`DELETE https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document/{documentID}`

## Authentication

Header: `authorization: <Voiceflow API key>`

## Path parameters

- `documentID` (string, required): ID of the document to target.

## Query parameters

- `projectEnvironmentIDOrAlias` (string, optional): environment alias such as `main`.

## Response

`200 OK` — the target document was deleted successfully.

## Example

```bash
curl -X DELETE \
  "https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document/doc-123?projectEnvironmentIDOrAlias=main" \
  -H "authorization: your-voiceflow-api-key"
```
