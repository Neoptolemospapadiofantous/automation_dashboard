<?php

namespace Tests\Feature\Runtime;

use App\Actions\Agents\CreateAgent;
use App\Models\Agent;
use App\Models\User;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Models\KbDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The dashboard surfaces (Chat page endpoints, Knowledge page, agent
 * health, signup) running on the native engine at the controller layer.
 */
class NativeDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'runtime.llm.anthropic.api_key' => 'sk-anthropic-test',
            'runtime.embeddings.openai_api_key' => 'sk-openai-test',
            'runtime.embeddings.dimensions' => 4,
        ]);
    }

    // ── Chat page (ChatController) ──────────────────────────────────────────

    public function test_chat_launch_uses_native_engine_and_bills_credits(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->text('Hello! What brings you here?'))]);

        $user = $this->userWithNativeAgent();
        $user->currentTeam->forceFill(['credit_balance' => 100])->save();

        $response = $this->actingAs($user)->postJson(route('chat.launch'));

        $response->assertOk()
            ->assertJsonPath('messages.0', 'Hello! What brings you here?')
            ->assertJsonPath('ended', false)
            ->assertJsonPath('captured', []);

        // 1 user-equivalent + 1 reply = 2 credits, same math as legacy.
        $this->assertSame(80, $user->currentTeam->fresh()->credit_balance);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'voiceflow.com'));
    }

    public function test_chat_interact_records_conversation_and_replies(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->text('Hi!'))
                ->push($this->text('We integrate with everything.')),
        ]);

        $user = $this->userWithNativeAgent();
        $user->currentTeam->forceFill(['credit_balance' => 100])->save();

        $launch = $this->actingAs($user)->postJson(route('chat.launch'))->json();

        $reply = $this->actingAs($user)->postJson(route('chat.interact'), [
            'user_id' => $launch['user_id'],
            'message' => 'what do you integrate with?',
        ]);

        $reply->assertOk()->assertJsonPath('messages.0', 'We integrate with everything.');

        // Transcript recorded: user message + agent reply.
        $this->assertDatabaseHas('messages', ['role' => 'user', 'text' => 'what do you integrate with?']);
        $this->assertDatabaseHas('messages', ['role' => 'agent', 'text' => 'We integrate with everything.']);
    }

    public function test_chat_health_reports_native_engine(): void
    {
        $user = $this->userWithNativeAgent();

        $this->actingAs($user)->getJson(route('chat.health'))
            ->assertOk()
            ->assertJsonPath('engine', 'native')
            ->assertJsonPath('ok', true);
    }

    public function test_chat_out_of_credits_blocks_native_turn(): void
    {
        $user = $this->userWithNativeAgent();
        $user->currentTeam->forceFill(['credit_balance' => 0])->save();

        $this->actingAs($user)->postJson(route('chat.launch'))->assertStatus(402);
    }

    public function test_agent_health_button_reports_native(): void
    {
        $user = $this->userWithNativeAgent();
        $agent = $user->currentTeam->currentAgent;

        $this->actingAs($user)->postJson(route('agents.health', $agent->slug))
            ->assertOk()
            ->assertJsonPath('engine', 'native');
    }

    // ── Knowledge page native branch ────────────────────────────────────────

    public function test_knowledge_index_lists_native_documents_in_native_shape(): void
    {
        $this->fakeEmbeddings();

        $user = $this->userWithNativeAgent();
        $agent = $user->currentTeam->currentAgent;
        app(KnowledgeStore::class)->ingestDocument($agent->id, 'Pricing FAQ', 'We charge $99.', ['source' => 'text']);

        $this->actingAs($user)->get(route('knowledge.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Knowledge/Index')
                ->where('configured', true)
                ->where('total', 1)
                ->where('documents.0.data.name', 'Pricing FAQ')
                ->where('documents.0.status.type', 'SUCCESS')
            );
    }

    public function test_knowledge_store_text_ingests_natively(): void
    {
        $this->fakeEmbeddings();

        $user = $this->userWithNativeAgent();

        $this->actingAs($user)->post(route('knowledge.text'), [
            'name' => 'Returns policy',
            'text' => 'Returns are accepted within 30 days of purchase.',
        ])->assertRedirect();

        $this->assertDatabaseHas('kb_documents', [
            'agent_id' => $user->currentTeam->currentAgent->id,
            'title' => 'Returns policy',
        ]);
    }

    public function test_knowledge_show_and_destroy_native_document(): void
    {
        $this->fakeEmbeddings();

        $user = $this->userWithNativeAgent();
        $agent = $user->currentTeam->currentAgent;
        $docId = app(KnowledgeStore::class)->ingestDocument($agent->id, 'Doomed', 'short text', ['source' => 'text']);

        $this->actingAs($user)->getJson(route('knowledge.show', $docId))
            ->assertOk()
            ->assertJsonPath('data.name', 'Doomed')
            ->assertJsonStructure(['chunks', 'metadata']);

        $this->actingAs($user)->delete(route('knowledge.destroy', $docId))->assertRedirect();
        $this->assertNull(KbDocument::find($docId));
    }

    public function test_knowledge_query_synthesizes_answer_from_chunks(): void
    {
        $this->fakeEmbeddings();
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->text('The Starter plan is $99/month.')),
            // openai fake already registered by fakeEmbeddings (Http::fake merges)
        ]);

        $user = $this->userWithNativeAgent();
        $agent = $user->currentTeam->currentAgent;
        app(KnowledgeStore::class)->ingestDocument($agent->id, 'Pricing', 'Starter costs $99/month.', ['source' => 'text']);

        $this->actingAs($user)->postJson(route('knowledge.query'), ['question' => 'price?'])
            ->assertOk()
            ->assertJsonPath('answer', 'The Starter plan is $99/month.')
            ->assertJsonPath('chunks.0.source.name', 'Pricing');
    }

    // ── Streaming (SSE) ──────────────────────────────────────────────────────

    public function test_chat_interact_stream_emits_trace_and_summary_and_bills(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->text('Streamed reply!')),
        ]);

        $user = $this->userWithNativeAgent();
        $user->currentTeam->forceFill(['credit_balance' => 100])->save();

        $response = $this->actingAs($user)->post(route('chat.interact-stream'), [
            'user_id' => 'web-stream-1',
            'message' => 'hello',
        ]);

        $response->assertOk();
        $body = $response->streamedContent();

        // SSE protocol the Chat page speaks: trace frame with the message…
        $this->assertStringContainsString('event: trace', $body);
        $this->assertStringContainsString('Streamed reply!', $body);
        // …then a summary frame with the billing total: 2 messages
        // (1 user + 1 reply) x haiku's 10 credits = 20.
        $this->assertStringContainsString('event: summary', $body);
        $this->assertStringContainsString('"messages_billed":20', $body);

        // Billing + transcript recording ran after the stream.
        $this->assertSame(80, $user->currentTeam->fresh()->credit_balance);
        $this->assertDatabaseHas('messages', ['role' => 'agent', 'text' => 'Streamed reply!']);
    }

    public function test_chat_interact_stream_emits_402_error_frame_when_dry(): void
    {
        $user = $this->userWithNativeAgent();
        $user->currentTeam->forceFill(['credit_balance' => 0])->save();

        $response = $this->actingAs($user)->post(route('chat.interact-stream'), [
            'user_id' => 'web-stream-2',
            'message' => 'hello',
        ]);

        $response->assertOk(); // SSE always 200; the error rides the frame
        $body = $response->streamedContent();
        $this->assertStringContainsString('event: error', $body);
        $this->assertStringContainsString('"status":402', $body);
    }

    // ── Signup provisioning ─────────────────────────────────────────────────

    public function test_create_agent_is_always_native(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $agent = (new CreateAgent)->execute($user->currentTeam, 'My native agent');

        $this->assertSame(Agent::RUNTIME_NATIVE, $agent->runtime_mode);
        $this->assertSame(Agent::STATUS_ACTIVE, $agent->status);
        $this->assertSame(Agent::MODE_MANAGED, $agent->mode);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function userWithNativeAgent(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'runtime_mode' => 'native',
            'status' => 'active',
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id, 'credit_balance' => 100])->save();

        return $user->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function text(string $text): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ];
    }

    private function fakeEmbeddings(): void
    {
        Http::fake([
            'api.openai.com/*' => function (Request $request) {
                $inputs = (array) $request->data()['input'];
                $data = [];
                foreach (array_values($inputs) as $i => $text) {
                    $data[] = ['index' => $i, 'embedding' => [0.5, 0.5, 0.5, 0.5]];
                }

                return Http::response(['data' => $data]);
            },
        ]);
    }
}
