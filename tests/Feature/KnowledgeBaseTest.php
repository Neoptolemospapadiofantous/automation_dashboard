<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
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

    public function test_index_shares_current_agent_so_ui_can_scope_copy(): void
    {
        // The UI relies on `agent.name` to caption the page ("Documents
        // belong to <name>"). Without this share, switching agents in the
        // picker wouldn't surface in the KB header even though the docs
        // themselves change.
        Http::fake(['*' => Http::response(['total' => 0, 'data' => []])]);

        $user = $this->user();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'name' => 'Sales bot',
            'status' => Agent::STATUS_ACTIVE,
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        $this->actingAs($user->fresh())->get(route('knowledge.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('agent.name', 'Sales bot')
                ->where('agent.id', $agent->id)
            );
    }

    public function test_index_accepts_type_filter_and_forwards_to_voiceflow(): void
    {
        Http::fake([
            'realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document*' => Http::response([
                'total' => 0, 'data' => [],
            ]),
        ]);

        $this->actingAs($this->user())->get(route('knowledge.index', ['type' => 'pdf']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filter.type', 'pdf'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'documentType=pdf'));
    }

    public function test_index_rejects_unknown_type_filter(): void
    {
        // The whitelist (accepted_types) is the contract — anything outside
        // it fails validation, not silently passes through to Voiceflow.
        Http::fake();

        $this->actingAs($this->user())->get(route('knowledge.index', ['type' => 'wat']))
            ->assertSessionHasErrors('type');
    }

    public function test_show_returns_document_with_chunks(): void
    {
        Http::fake([
            'realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document/doc-99*' => Http::response([
                'data' => ['documentID' => 'doc-99', 'data' => ['type' => 'pdf', 'name' => 'manual.pdf']],
                'chunks' => [
                    ['chunkID' => 'c1', 'content' => 'Section one body', 'metadata' => []],
                    ['chunkID' => 'c2', 'content' => 'Section two body', 'metadata' => []],
                ],
                'metadata' => [],
            ]),
        ]);

        $this->actingAs($this->user())
            ->getJson(route('knowledge.show', 'doc-99'))
            ->assertOk()
            ->assertJsonPath('data.documentID', 'doc-99')
            ->assertJsonCount(2, 'chunks');
    }

    public function test_destroy_deletes_document(): void
    {
        Http::fake([
            'realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document/doc-42*' => Http::response([], 200),
        ]);

        $this->actingAs($this->user())
            ->delete(route('knowledge.destroy', 'doc-42'))
            ->assertRedirect();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/v1alpha1/public/knowledge-base/document/doc-42'));
    }

    public function test_store_file_uploads_to_voiceflow(): void
    {
        Http::fake([
            'realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document' => Http::response([
                'data' => ['documentID' => 'doc-new', 'status' => ['type' => 'PENDING']],
            ], 201),
        ]);

        $file = File::fake()->createWithContent('handbook.pdf', '%PDF-1.4 fake');

        $this->actingAs($this->user())
            ->post(route('knowledge.file'), ['file' => $file])
            ->assertRedirect();

        // Multipart bodies don't expose JSON keys via $request[]; check that
        // a POST hit the right path. Service-level upload mechanics covered
        // by integration with Voiceflow live.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/v1alpha1/public/knowledge-base/document'));
    }

    public function test_store_file_rejects_unsupported_mime(): void
    {
        $file = File::fake()->createWithContent('photo.jpg', "\xFF\xD8\xFF\xE0 jpeg");

        $this->actingAs($this->user())
            ->post(route('knowledge.file'), ['file' => $file])
            ->assertSessionHasErrors('file');
    }

    public function test_store_file_rejects_oversize_upload(): void
    {
        // 11 MB — over the 10 MB ceiling. Laravel validation catches before
        // we open a stream to Voiceflow.
        $file = File::fake()->create('huge.pdf', 11 * 1024, 'application/pdf');

        $this->actingAs($this->user())
            ->post(route('knowledge.file'), ['file' => $file])
            ->assertSessionHasErrors('file');
    }
}
