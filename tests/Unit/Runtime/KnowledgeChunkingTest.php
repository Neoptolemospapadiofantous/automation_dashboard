<?php

namespace Tests\Unit\Runtime;

use App\Runtime\Knowledge\Chunker;
use App\Runtime\Knowledge\VectorSearch;
use Tests\TestCase;

/**
 * Pure KB algorithms — the Chunker's segmentation and VectorSearch's cosine
 * math. No DB and no embeddings provider; extends Tests\TestCase only so the
 * splitter can read its chunk-size config. The DB/HTTP-bound ingest + query
 * flows stay in tests/Feature/Runtime/KnowledgeBaseTest.php.
 */
class KnowledgeChunkingTest extends TestCase
{
    public function test_chunker_returns_single_chunk_for_short_text(): void
    {
        $chunks = (new Chunker)->chunk('Short pricing note.');

        $this->assertSame(['Short pricing note.'], $chunks);
    }

    public function test_chunker_splits_long_documents_with_full_coverage(): void
    {
        config(['runtime.rag.chunk_size_tokens' => 50, 'runtime.rag.chunk_overlap_tokens' => 5]);

        $paragraphs = [];
        for ($i = 1; $i <= 10; $i++) {
            $paragraphs[] = "Paragraph {$i} talks about feature {$i} in enough words to take real space in the budget.";
        }
        $chunks = (new Chunker)->chunk(implode("\n\n", $paragraphs));

        $this->assertGreaterThan(1, count($chunks));
        // Every paragraph's content survives somewhere.
        $joined = implode(' ', $chunks);
        for ($i = 1; $i <= 10; $i++) {
            $this->assertStringContainsString("feature {$i}", $joined);
        }
    }

    public function test_cosine_similarity_math(): void
    {
        $v = new VectorSearch;

        $this->assertEqualsWithDelta(1.0, $v->cosine([1, 0], [1, 0]), 0.0001);
        $this->assertEqualsWithDelta(0.0, $v->cosine([1, 0], [0, 1]), 0.0001);
        $this->assertEqualsWithDelta(-1.0, $v->cosine([1, 0], [-1, 0]), 0.0001);
        $this->assertSame(0.0, $v->cosine([], [1.0]));
    }
}
