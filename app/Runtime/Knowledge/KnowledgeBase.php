<?php

namespace App\Runtime\Knowledge;

use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Models\KbChunk;
use App\Runtime\Models\KbDocument;
use Illuminate\Support\Facades\DB;

/**
 * The KnowledgeStore implementation: ingest → chunk → embed → store;
 * query → embed → cosine-rank → top-k.
 *
 * chunk_count is recomputed inside the ingest transaction (audit finding:
 * the denormalized counter must never drift from the real chunk rows).
 */
class KnowledgeBase implements KnowledgeStore
{
    public function __construct(
        protected Chunker $chunker,
        protected EmbeddingService $embeddings,
        protected VectorSearch $vectors,
    ) {}

    public function ingestDocument(int $agentId, string $title, string $content, array $metadata = []): int
    {
        $chunks = $this->chunker->chunk($content);
        // Embed BEFORE the transaction — long HTTP calls don't belong
        // inside a DB transaction.
        $vectors = $this->embeddings->embed($chunks);
        $model = $this->embeddings->model();

        return DB::transaction(function () use ($agentId, $title, $content, $metadata, $chunks, $vectors, $model): int {
            $document = KbDocument::create([
                'agent_id' => $agentId,
                'title' => $title,
                'source' => (string) ($metadata['source'] ?? 'text'),
                'source_url' => isset($metadata['source_url']) ? (string) $metadata['source_url'] : null,
                'raw_content' => $content,
                'metadata' => $metadata,
                'chunk_count' => 0,
            ]);

            foreach ($chunks as $i => $chunk) {
                KbChunk::create([
                    'document_id' => $document->id,
                    'agent_id' => $agentId,
                    'position' => $i,
                    'content' => $chunk,
                    'embedding' => $vectors[$i],
                    'embedding_model' => $model,
                ]);
            }

            // Recompute from the source of truth — never trust the loop count.
            $document->update(['chunk_count' => $document->chunks()->count()]);

            return $document->id;
        });
    }

    public function search(int $agentId, string $question, int $topK = 5): array
    {
        $question = trim($question);
        if ($question === '') {
            return [];
        }

        $chunks = KbChunk::query()
            ->where('agent_id', $agentId)
            ->with('document:id,title')
            ->get(['id', 'document_id', 'agent_id', 'content', 'embedding', 'metadata']);

        if ($chunks->isEmpty()) {
            return [];
        }

        $queryVector = $this->embeddings->embed([$question])[0];
        $minSimilarity = (float) config('runtime.rag.min_similarity');

        $scored = [];
        foreach ($chunks as $chunk) {
            $score = $this->vectors->cosine($queryVector, (array) $chunk->embedding);
            if ($score < $minSimilarity) {
                continue;
            }
            $scored[] = [
                'chunk' => (string) $chunk->content,
                'chunk_id' => (int) $chunk->id,
                'document_id' => (int) $chunk->document_id,
                'document_title' => (string) ($chunk->document->title ?? ''),
                'score' => round($score, 4),
                'metadata' => (array) ($chunk->metadata ?? []),
            ];
        }

        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, max(1, $topK));
    }

    public function deleteDocument(int $agentId, int $documentId): void
    {
        // Chunks cascade via the document_id FK.
        KbDocument::query()
            ->where('agent_id', $agentId)
            ->where('id', $documentId)
            ->delete();
    }

    public function listDocuments(int $agentId): array
    {
        return KbDocument::query()
            ->where('agent_id', $agentId)
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'chunk_count', 'created_at', 'metadata'])
            ->map(fn (KbDocument $d) => [
                'id' => (int) $d->id,
                'title' => (string) $d->title,
                'chunk_count' => (int) $d->chunk_count,
                'created_at' => (string) $d->created_at->toIso8601String(),
                'metadata' => (array) ($d->metadata ?? []),
            ])
            ->all();
    }
}
