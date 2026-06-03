# Voiceflow Knowledge Base API

Mirrored from `https://docs.voiceflow.com/api-reference/knowledge-base-api/` and the public KB query endpoint.

All document CRUD endpoints live on `https://realtime-api.voiceflow.com`. The KB query endpoint lives on `https://general-runtime.voiceflow.com`. All endpoints authenticate via the `authorization` header with a Voiceflow workspace API key.

| File | Method | Path | Purpose |
|------|--------|------|---------|
| [overview.md](./overview.md) | — | — | Surface overview, hosts, auth, supported document types. |
| [search-documents.md](./search-documents.md) | GET | `/v1alpha1/public/knowledge-base/document` | List/paginate documents, filter by type. |
| [create-document.md](./create-document.md) | POST | `/v1alpha1/public/knowledge-base/document` | Create a document from URL or uploaded file. |
| [get-document.md](./get-document.md) | GET | `/v1alpha1/public/knowledge-base/document/{documentID}` | Fetch a document with chunks and metadata. |
| [replace-document.md](./replace-document.md) | PUT | `/v1alpha1/public/knowledge-base/document/{documentID}` | Replace an existing document's content. |
| [delete-document.md](./delete-document.md) | DELETE | `/v1alpha1/public/knowledge-base/document/{documentID}` | Delete a document by ID. |
| [update-document-metadata.md](./update-document-metadata.md) | PATCH | `/v1alpha1/public/knowledge-base/document/{documentID}` | Patch document-level metadata tags. |
| [update-chunk-metadata.md](./update-chunk-metadata.md) | PATCH | `/v1alpha1/public/knowledge-base/document/{documentID}/chunk/{chunkID}` | Patch metadata for a specific chunk. |
| [upload-table-document.md](./upload-table-document.md) | POST | `/v1alpha1/public/knowledge-base/document/upload/table` | Upload structured table rows + schema. |
| [query.md](./query.md) | POST | `/knowledge-base/query` (host: `general-runtime.voiceflow.com`) | Query KB and optionally synthesize an LLM answer. |
