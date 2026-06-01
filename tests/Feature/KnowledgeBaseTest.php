<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.voiceflow.api_key', 'VF.DM.test-key');
        config()->set('services.voiceflow.project_id', 'proj-123');
        config()->set('services.voiceflow.environment', 'main');
        config()->set('services.voiceflow.realtime_url', 'https://realtime-api.voiceflow.com');
        config()->set('services.voiceflow.runtime_url', 'https://general-runtime.voiceflow.com');
    }

    private function user(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    public function test_index_lists_documents(): void
    {
        Http::fake([
            'realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document*' => Http::response([
                'total' => 1,
                'data' => [[
                    'documentID' => 'doc-1',
                    'data' => ['type' => 'url', 'name' => 'Pricing', 'url' => 'https://x.com/pricing'],
                    'status' => ['type' => 'SUCCESS'],
                    'updatedAt' => '2026-06-01T00:00:00Z',
                ]],
            ]),
        ]);

        $this->actingAs($this->user())->get(route('knowledge.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Knowledge/Index')
                ->where('configured', true)
                ->has('documents', 1)
                ->where('documents.0.documentID', 'doc-1')
            );
    }

    public function test_add_url_document_posts_to_voiceflow(): void
    {
        Http::fake([
            'realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document' => Http::response([
                'data' => ['documentID' => 'doc-2', 'status' => ['type' => 'PENDING']],
            ], 201),
        ]);

        $this->actingAs($this->user())
            ->post(route('knowledge.url'), ['url' => 'https://example.com/faq', 'name' => 'FAQ'])
            ->assertRedirect();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1alpha1/public/knowledge-base/document')
                && $request['data']['type'] === 'url'
                && $request['data']['url'] === 'https://example.com/faq';
        });
    }

    public function test_query_returns_answer_and_chunks(): void
    {
        Http::fake([
            'general-runtime.voiceflow.com/knowledge-base/query' => Http::response([
                'type' => 'completion',
                'output' => 'Our pricing starts at $49/mo.',
                'chunks' => [
                    ['content' => 'Plans: Starter $49', 'score' => 0.9, 'documentID' => 'doc-1', 'source' => ['name' => 'Pricing']],
                ],
            ]),
        ]);

        $this->actingAs($this->user())
            ->postJson(route('knowledge.query'), ['question' => 'How much does it cost?'])
            ->assertOk()
            ->assertJsonPath('answer', 'Our pricing starts at $49/mo.')
            ->assertJsonPath('chunks.0.source', 'Pricing');
    }

    public function test_endpoints_503_when_unconfigured(): void
    {
        config()->set('services.voiceflow.api_key', null);

        $this->actingAs($this->user())
            ->postJson(route('knowledge.query'), ['question' => 'hi'])
            ->assertStatus(503);
    }
}
