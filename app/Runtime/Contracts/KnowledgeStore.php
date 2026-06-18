<?php

namespace App\Runtime\Contracts;

/**
 * Per-agent knowledge base: ingest documents at upload time, retrieve
 * relevant chunks at query time. The interface stays storage-agnostic so
 * we can run on JSON-stored embeddings + in-process cosine similarity
 * during development (fast tests, no pgvector required), then swap to
 * pgvector or Pinecone for production scale by binding a different
 * implementation in the service container.
 *
 * Implementation lives in app/Runtime/Knowledge/KnowledgeBase.php.
 *
 * @api
 */
interface KnowledgeStore
{
    /**
     * Ingest a new document for the given agent. The implementation
     * chunks the content, calls the embedding service per chunk, and
     * stores both the document row and its chunks.
     *
     * Returns the kb_documents.id of the newly created document.
     *
     * @param  array<string, mixed>  $metadata  Free-form tags (e.g. source URL, type)
     */
    public function ingestDocument(int $agentId, string $title, string $content, array $metadata = []): int;

    /**
     * Retrieve the top-k chunks most relevant to a natural-language
     * question, scoped to this agent's documents.
     *
     * Each result has the chunk text, the parent document title (for
     * citation rendering), document_id + chunk_id (for dedup across
     * same-doc chunks and click-through to source), and the similarity
     * score (1.0 = identical, 0.0 = unrelated).
     *
     * @return list<array{chunk: string, chunk_id: int, document_id: int, document_title: string, score: float, metadata: array<string, mixed>}>
     */
    public function search(int $agentId, string $question, int $topK = 5): array;

    /**
     * Whether this agent has any ingested knowledge at all. Cheap existence
     * check used by the runtime's confidence gate: an agent with no KB is a
     * pure instructions/tool agent, so a low retrieval score must NOT be
     * read as "couldn't answer" — there was nothing to retrieve from.
     */
    public function hasDocuments(int $agentId): bool;

    /**
     * Delete a document and all its chunks. Idempotent.
     */
    public function deleteDocument(int $agentId, int $documentId): void;

    /**
     * List the documents belonging to an agent (for the dashboard's
     * Knowledge page). Returns lightweight summaries — no chunk content.
     *
     * @return list<array{id: int, title: string, chunk_count: int, created_at: string, metadata: array<string, mixed>}>
     */
    public function listDocuments(int $agentId): array;
}
