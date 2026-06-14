<?php

namespace Tests\Feature\Runtime;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Exceptions\Misconfigured;
use App\Runtime\Exceptions\UpstreamUnavailable;
use App\Runtime\Knowledge\Chunker;
use App\Runtime\Knowledge\VectorSearch;
use App\Runtime\Models\KbDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'runtime.embeddings.openai_api_key' => 'sk-test',
            // 4-dim vectors keep test fixtures readable; the dim check
            // validates against THIS config so it still exercises.
            'runtime.embeddings.dimensions' => 4,
        ]);
    }

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

    public function test_ingest_chunks_embeds_and_recounts(): void
    {
        $this->fakeEmbeddings();
        config(['runtime.rag.chunk_size_tokens' => 50, 'runtime.rag.chunk_overlap_tokens' => 0]);

        $agent = $this->agent();
        $kb = app(KnowledgeStore::class);

        $long = implode("\n\n", array_fill(0, 8, 'A paragraph about pricing plans with plenty of words to overflow a tiny budget.'));
        $docId = $kb->ingestDocument($agent->id, 'Pricing', $long, ['source' => 'text']);

        $this->assertDatabaseHas('kb_documents', ['id' => $docId, 'agent_id' => $agent->id, 'title' => 'Pricing']);
        $doc = KbDocument::find($docId);
        $this->assertGreaterThan(1, $doc->chunk_count);
        // The denormalized counter matches the real rows (audit finding).
        $this->assertSame($doc->chunks()->count(), $doc->chunk_count);
    }

    public function test_search_ranks_by_cosine_and_filters_threshold(): void
    {
        config(['runtime.rag.min_similarity' => 0.5]);

        // Deterministic embeddings: pricing → x-axis, support → y-axis.
        $this->fakeEmbeddings([
            'pricing' => [1.0, 0.0, 0.0, 0.0],
            'support' => [0.0, 1.0, 0.0, 0.0],
        ]);

        $agent = $this->agent();
        $kb = app(KnowledgeStore::class);
        $kb->ingestDocument($agent->id, 'Pricing doc', 'pricing', ['source' => 'text']);
        $kb->ingestDocument($agent->id, 'Support doc', 'support', ['source' => 'text']);

        $results = $kb->search($agent->id, 'pricing', 5);

        $this->assertCount(1, $results); // support doc scores 0.0 < 0.5 threshold
        $this->assertSame('Pricing doc', $results[0]['document_title']);
        $this->assertGreaterThan(0.9, $results[0]['score']);
        $this->assertArrayHasKey('chunk_id', $results[0]);
        $this->assertArrayHasKey('document_id', $results[0]);
    }

    public function test_search_is_agent_scoped(): void
    {
        $this->fakeEmbeddings();

        $agentA = $this->agent();
        $agentB = $this->agent();

        $kb = app(KnowledgeStore::class);
        $kb->ingestDocument($agentA->id, 'A-only doc', 'alpha facts', ['source' => 'text']);

        $this->assertNotEmpty($kb->search($agentA->id, 'alpha facts', 5));
        $this->assertSame([], $kb->search($agentB->id, 'alpha facts', 5));
    }

    public function test_delete_document_cascades_chunks(): void
    {
        $this->fakeEmbeddings();

        $agent = $this->agent();
        $kb = app(KnowledgeStore::class);
        $docId = $kb->ingestDocument($agent->id, 'Doomed', 'short doc', ['source' => 'text']);

        $kb->deleteDocument($agent->id, $docId);

        $this->assertDatabaseMissing('kb_documents', ['id' => $docId]);
        $this->assertDatabaseMissing('kb_chunks', ['document_id' => $docId]);
    }

    public function test_dimension_mismatch_throws_upstream_unavailable(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'data' => [['index' => 0, 'embedding' => [0.1, 0.2]]], // 2 dims, expected 4
            ]),
        ]);

        $this->expectException(UpstreamUnavailable::class);
        app(KnowledgeStore::class)->ingestDocument($this->agent()->id, 'Bad', 'text', []);
    }

    public function test_missing_openai_key_throws_misconfigured(): void
    {
        config(['runtime.embeddings.openai_api_key' => '']);

        $this->expectException(Misconfigured::class);
        app(KnowledgeStore::class)->ingestDocument($this->agent()->id, 'Doc', 'text', []);
    }

    private function agent(): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();

        return Agent::factory()->for($user->currentTeam)->create(['runtime_mode' => 'native']);
    }

    /**
     * Fake OpenAI embeddings: looks up exact-text vectors from $map,
     * defaulting to a fixed unit vector. Honors batch inputs + index order.
     *
     * @param  array<string, list<float>>  $map
     */
    private function fakeEmbeddings(array $map = []): void
    {
        Http::fake([
            'api.openai.com/*' => function (Request $request) use ($map) {
                $inputs = (array) $request->data()['input'];
                $data = [];
                foreach (array_values($inputs) as $i => $text) {
                    $data[] = [
                        'index' => $i,
                        'embedding' => $map[$text] ?? [0.5, 0.5, 0.5, 0.5],
                    ];
                }

                return Http::response(['data' => $data]);
            },
        ]);
    }
}
