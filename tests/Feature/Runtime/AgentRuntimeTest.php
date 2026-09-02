<?php

namespace Tests\Feature\Runtime;

use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\User;
use App\Runtime\AgentRuntime;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\LLM\SystemPrompt;
use App\Runtime\Models\RuntimeSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end native runtime: launch → discovery → tool calls → wrapup,
 * with Anthropic + OpenAI faked at the HTTP layer. The final test drives
 * the whole stack through the public embed endpoints.
 */
class AgentRuntimeTest extends TestCase
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

    public function test_launch_greets_and_advances_to_discovery(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->textResponse('Hi! What brings you here today?')),
        ]);

        $agent = $this->agent();
        $traces = app(AgentRuntime::class)->launch($agent, 'v1');

        $this->assertSame('Hi! What brings you here today?', $traces[0]['payload']['message']);

        $session = RuntimeSession::where('visitor_id', 'v1')->first();
        $this->assertSame('discovery', $session->flow_state); // greeting autoNext
        $this->assertNotEmpty($session->history);
    }

    public function test_launch_with_published_greeting_skips_the_llm(): void
    {
        Http::fake();

        $agent = $this->agent();
        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => AgentConfigVersion::STATUS_PUBLISHED,
            'config' => ['instructions' => '', 'greeting' => 'Hi! Welcome to Acme.', 'model_tier' => 'haiku'],
            'published_at' => now(),
        ]);

        $traces = app(AgentRuntime::class)->launch($agent, 'v-static');

        // Served verbatim — zero tokens, no API call.
        $this->assertSame('Hi! Welcome to Acme.', $traces[0]['payload']['message']);
        Http::assertNothingSent();

        // Session state matches what an LLM greeting turn would have left:
        // greeting auto-advanced, history seeded for the next turn.
        $session = RuntimeSession::where('visitor_id', 'v-static')->first();
        $this->assertSame('discovery', $session->flow_state);
        $this->assertCount(2, $session->history);
    }

    public function test_wrapup_state_can_escalate_to_a_human(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->toolUseResponse('request_handoff', ['reason' => 'Visitor asked for a human.']))
                ->push($this->textResponse('Connecting you with the team now.')),
        ]);

        $agent = $this->agent();
        $this->seedSession($agent, 'v-wrap', 'wrapup');

        $traces = app(AgentRuntime::class)->sendText($agent, 'v-wrap', 'Can I talk to a real person?');

        $this->assertSame('Connecting you with the team now.', $traces[0]['payload']['message']);
        // wrapup offers the escalation + lead-correction tools alongside
        // query_kb and end_session.
        Http::assertSent(function (Request $request): bool {
            $names = array_column((array) ($request->data()['tools'] ?? []), 'name');

            return in_array('request_handoff', $names, true)
                && in_array('capture_lead', $names, true);
        });
    }

    public function test_launch_resets_a_prior_session(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse('Welcome back!'))]);

        $agent = $this->agent();
        $runtime = app(AgentRuntime::class);

        $runtime->launch($agent, 'v1');
        RuntimeSession::where('visitor_id', 'v1')->first()
            ->forceFill(['variables' => ['budget' => 'x'], 'flow_state' => 'wrapup'])->save();

        $runtime->launch($agent, 'v1');

        $session = RuntimeSession::where('visitor_id', 'v1')->first();
        $this->assertSame('discovery', $session->flow_state);
        $this->assertArrayNotHasKey('budget', (array) $session->variables);
    }

    public function test_send_text_runs_tool_loop_captures_lead_and_transitions(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                // Turn: model decides to capture the lead…
                ->push($this->toolUseResponse('capture_lead', [
                    'name' => 'Bob Buyer', 'email' => 'bob@buyer.co', 'score' => 80,
                ]))
                // …then confirms to the visitor.
                ->push($this->textResponse('All set, Bob — the team will reach out shortly!')),
        ]);

        $agent = $this->agent();
        $this->seedSession($agent, 'v1', 'discovery');

        $traces = app(AgentRuntime::class)->sendText($agent, 'v1', "I'm Bob, bob@buyer.co — let's talk");

        $this->assertStringContainsString('All set, Bob', $traces[0]['payload']['message']);
        $this->assertDatabaseHas('leads', ['email' => 'bob@buyer.co', 'agent_id' => $agent->id, 'status' => 'new']);
        // capture_lead success transitions discovery → wrapup.
        $this->assertSame('wrapup', RuntimeSession::where('visitor_id', 'v1')->first()->flow_state);
    }

    public function test_kb_context_is_injected_into_the_system_prompt(): void
    {
        Http::fake([
            'api.openai.com/*' => function (Request $request) {
                $inputs = (array) $request->data()['input'];
                $data = [];
                foreach (array_values($inputs) as $i => $text) {
                    $data[] = ['index' => $i, 'embedding' => [1.0, 0.0, 0.0, 0.0]];
                }

                return Http::response(['data' => $data]);
            },
            'api.anthropic.com/*' => Http::response($this->textResponse('The Starter plan is $99/mo.')),
        ]);

        $agent = $this->agent();
        app(KnowledgeStore::class)->ingestDocument($agent->id, 'Pricing', 'Starter plan costs $99 per month.', ['source' => 'text']);
        $this->seedSession($agent, 'v1', 'discovery');

        app(AgentRuntime::class)->sendText($agent, 'v1', 'how much does it cost?');

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'anthropic')) {
                return false;
            }

            return str_contains(SystemPrompt::toText($request->data()['system'] ?? ''), 'Starter plan costs $99 per month.');
        });
    }

    public function test_max_turns_cap_short_circuits_without_llm_call(): void
    {
        config(['runtime.safety.max_turns_per_session' => 1]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse('one reply'))]);

        $agent = $this->agent();
        $runtime = app(AgentRuntime::class);
        $this->seedSession($agent, 'v1', 'discovery');

        $runtime->sendText($agent, 'v1', 'first turn'); // consumes the budget
        Http::assertSentCount(1);

        $traces = $runtime->sendText($agent, 'v1', 'second turn');

        Http::assertSentCount(1); // no extra LLM call
        $this->assertStringContainsString('reached its limit', $traces[0]['payload']['message']);
    }

    public function test_runaway_tool_loop_is_capped(): void
    {
        config(['runtime.safety.max_tool_calls_per_turn' => 2]);
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->toolUseResponse('set_variable', ['name' => 'a', 'value' => '1']))
                ->push($this->toolUseResponse('set_variable', ['name' => 'b', 'value' => '2']))
                ->push($this->toolUseResponse('set_variable', ['name' => 'c', 'value' => '3'])), // would exceed cap
        ]);

        $agent = $this->agent();
        $this->seedSession($agent, 'v1', 'discovery');

        $traces = app(AgentRuntime::class)->sendText($agent, 'v1', 'loop please');

        $this->assertStringContainsString('teammate', $traces[0]['payload']['message']);
        $vars = RuntimeSession::where('visitor_id', 'v1')->first()->variables;
        $this->assertSame('1', $vars['a']);
        $this->assertSame('2', $vars['b']);
        $this->assertArrayNotHasKey('c', $vars); // third call never dispatched
    }

    public function test_stream_text_yields_tool_message_done(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->toolUseResponse('capture_lead', ['name' => 'Eve', 'email' => 'eve@x.co']))
                ->push($this->textResponse('Saved — talk soon, Eve!')),
        ]);

        $agent = $this->agent();
        $this->seedSession($agent, 'v1', 'discovery');

        $events = iterator_to_array(app(AgentRuntime::class)->streamText($agent, 'v1', 'eve@x.co here'));

        $this->assertSame('tool', $events[0]['event']);
        $this->assertSame(['name' => 'capture_lead', 'ok' => true], $events[0]['data']);
        $this->assertSame('message', $events[1]['event']);
        $this->assertStringContainsString('Saved', $events[1]['data']['message']);
        $this->assertSame('done', $events[2]['event']);
        $this->assertSame('wrapup', $events[2]['data']['state']);
    }

    public function test_end_session_is_idempotent_via_contract(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse('hi'))]);

        $agent = $this->agent();
        $runtime = app(AgentRuntime::class);
        $runtime->launch($agent, 'v1');

        $runtime->endSession($agent, 'v1');
        $runtime->endSession($agent, 'v1');

        $this->assertSame('ended', RuntimeSession::where('visitor_id', 'v1')->first()->flow_state);
    }

    public function test_embed_endpoints_serve_a_native_agent_end_to_end(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->textResponse('Welcome to Flowstack! How can I help?'))
                ->push($this->textResponse('Starter is $99/mo.')),
        ]);

        $agent = $this->agent();
        $agent->team->forceFill(['credit_balance' => 100])->save();

        // Public launch: native engine answers, visitor cookie issued.
        $launch = $this->postJson("/embed/{$agent->slug}/launch");
        $launch->assertOk()->assertJsonPath('traces.0.payload.message', 'Welcome to Flowstack! How can I help?');
        $visitorId = $launch->json('visitor_id');

        // Public interact: native engine answers, credit debited.
        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => $visitorId,
            'message' => 'price?',
        ])->assertOk()->assertJsonPath('traces.0.payload.message', 'Starter is $99/mo.');

        $this->assertSame(78, $agent->team->fresh()->credit_balance); // (1+1 reply) × 11
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'voiceflow.com'));
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function agent(): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();

        return Agent::factory()->for($user->currentTeam)->create([
            'runtime_mode' => 'native',
            'status' => 'active',
        ]);
    }

    private function seedSession(Agent $agent, string $visitorId, string $state): void
    {
        RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => $visitorId,
            'flow_state' => $state,
            'variables' => [],
            'history' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function textResponse(string $text): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function toolUseResponse(string $tool, array $input): array
    {
        return [
            'content' => [
                ['type' => 'text', 'text' => 'One moment…'],
                ['type' => 'tool_use', 'id' => 'toolu_'.uniqid(), 'name' => $tool, 'input' => $input],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 30, 'output_tokens' => 25],
        ];
    }
}
