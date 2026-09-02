<?php

namespace Tests\Feature\Runtime;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Exceptions\Misconfigured;
use App\Runtime\Exceptions\UpstreamUnavailable;
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

    public function test_reingesting_same_url_replaces_instead_of_duplicating(): void
    {
        $this->fakeEmbeddings();

        $agent = $this->agent();
        $kb = app(KnowledgeStore::class);

        $meta = ['source' => 'url', 'source_url' => 'https://acme.example.com'];
        $kb->ingestDocument($agent->id, 'Homepage', 'Old homepage copy about our product.', $meta);
        $newId = $kb->ingestDocument($agent->id, 'Homepage', 'New homepage copy — repositioned.', $meta);

        // One document survives, carrying the fresh content (a refresh, not a dupe).
        $docs = KbDocument::query()->where('agent_id', $agent->id)->get();
        $this->assertCount(1, $docs);
        $this->assertSame($newId, $docs->first()->id);
        $this->assertStringContainsString('repositioned', $docs->first()->raw_content);
    }

    public function test_reingesting_identical_content_never_duplicates(): void
    {
        $this->fakeEmbeddings();

        $agent = $this->agent();
        $kb = app(KnowledgeStore::class);

        $kb->ingestDocument($agent->id, 'Policies', 'Our refund policy is 30 days.', ['source' => 'text']);
        $kb->ingestDocument($agent->id, 'Policies', 'Our refund policy is 30 days.', ['source' => 'text']);

        $this->assertSame(1, KbDocument::query()->where('agent_id', $agent->id)->count());

        // Different content is a genuinely new document.
        $kb->ingestDocument($agent->id, 'Hours', 'We are open 9 to 5.', ['source' => 'text']);
        $this->assertSame(2, KbDocument::query()->where('agent_id', $agent->id)->count());
    }

    public function test_dedupe_is_agent_scoped(): void
    {
        $this->fakeEmbeddings();

        $agentA = $this->agent();
        $agentB = $this->agent();
        $kb = app(KnowledgeStore::class);

        $kb->ingestDocument($agentA->id, 'Doc', 'Shared identical content.', ['source' => 'text']);
        $kb->ingestDocument($agentB->id, 'Doc', 'Shared identical content.', ['source' => 'text']);

        // Same bytes, different tenants — both keep their copy.
        $this->assertSame(1, KbDocument::query()->where('agent_id', $agentA->id)->count());
        $this->assertSame(1, KbDocument::query()->where('agent_id', $agentB->id)->count());
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
            'api.openai.com/v1/embeddings' => Http::response([
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

    public function test_store_url_blocks_ssrf_targets(): void
    {
        // Any outbound request here would mean the guard let a private/
        // reserved destination through — the fake records it so we can assert.
        Http::fake();

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['runtime_mode' => 'native']);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        $blocked = [
            'http://169.254.169.254/latest/meta-data/', // cloud metadata
            'http://127.0.0.1/admin',                   // loopback
            'http://10.0.0.5/internal',                 // private network
            'ftp://example.com/secret',                 // non-http scheme
        ];

        foreach ($blocked as $url) {
            $this->actingAs($user)
                ->post(route('knowledge.url'), ['url' => $url])
                ->assertSessionHasErrors('url');
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('kb_documents', 0);
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
            'api.openai.com/v1/embeddings' => function (Request $request) use ($map) {
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
