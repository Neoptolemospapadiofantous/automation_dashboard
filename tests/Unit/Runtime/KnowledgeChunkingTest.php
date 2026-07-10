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

    public function test_markdown_headings_are_chunk_boundaries(): void
    {
        // Six short sections easily fit one 2000-char budget together — the
        // old packer returned the whole doc as ONE diluted chunk. Headings
        // must force per-topic chunks so retrieval scores stay sharp.
        $doc = implode("\n\n", [
            '# Frequently asked questions',
            "## What does it cost?\n\nStarter is 99 euro per month.",
            "## Is there a free trial?\n\nNo free trial; Starter is the way to try it.",
            "## How fast can I go live?\n\nAbout 60 seconds: pick a role and embed the widget.",
        ]);

        $chunks = (new Chunker)->chunk($doc);

        $this->assertCount(4, $chunks);
        $this->assertStringStartsWith('## What does it cost?', $chunks[1]);
        $this->assertStringContainsString('60 seconds', $chunks[3]);
        // No section bleeds into a neighbour's chunk.
        $this->assertStringNotContainsString('free trial', $chunks[1]);
    }

    public function test_oversized_section_still_splits_within_budget(): void
    {
        config(['runtime.rag.chunk_size_tokens' => 50, 'runtime.rag.chunk_overlap_tokens' => 0]);

        $long = [];
        for ($i = 1; $i <= 8; $i++) {
            $long[] = "Sentence {$i} of the pricing section carries enough words to consume budget.";
        }
        $doc = "## Pricing\n\n".implode("\n\n", $long)."\n\n## Contact\n\nEmail us anytime.";

        $chunks = (new Chunker)->chunk($doc);

        $budget = 50 * 4;
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual($budget, mb_strlen($chunk));
        }
        // The tiny trailing section survives as its own chunk.
        $this->assertStringContainsString('Email us anytime.', implode(' ', $chunks));
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
