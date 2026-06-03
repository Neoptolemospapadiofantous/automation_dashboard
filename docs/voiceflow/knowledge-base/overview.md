---
title: Knowledge Base API Overview
method: N/A
path: N/A
host: realtime-api.voiceflow.com
auth: Workspace API key (authorization header)
summary: Overview of the Knowledge Base public API — document CRUD, search, chunk/metadata updates, table uploads, and KB query.
source: https://docs.voiceflow.com/api-reference/knowledge-base-api/overview.md
---

# Knowledge Base API Overview

The Knowledge Base public API supports document operations: search, get, create, upload, replace, update, delete.

## Supported document types

`URL`, `PDF`, `DOCX`, plain text, `CSV`, `XLSX`, or `table`.

## URL document refresh rates

`daily`, `weekly`, `monthly`, or `never`.

## Scope

The knowledge base is project-wide, with environment-specific pointers (e.g. `main`) supplied via `projectEnvironmentIDOrAlias`.

## Hosts (consolidated from individual endpoint pages)

- Document CRUD / search / chunk metadata / table upload: `https://realtime-api.voiceflow.com`
- KB query (answer synthesis): `https://general-runtime.voiceflow.com`

## Authentication

All endpoints use an `authorization` request header containing a Voiceflow API key (workspace-scoped).

The overview page on the public docs does not enumerate rate limits or common headers beyond `authorization` and `Content-Type`.
