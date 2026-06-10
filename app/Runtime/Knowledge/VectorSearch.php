<?php

namespace App\Runtime\Knowledge;

/**
 * In-process cosine similarity over JSON-stored embeddings.
 *
 * Fine for the launch scale: ~200 chunks/agent × 1536 floats compares in
 * single-digit milliseconds. The pgvector swap (HNSW index, server-side
 * similarity) slots in behind KnowledgeStore without touching callers —
 * see the kb_chunks migration notes.
 */
class VectorSearch
{
    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public function cosine(array $a, array $b): float
    {
        $count = min(count($a), count($b));
        if ($count === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
