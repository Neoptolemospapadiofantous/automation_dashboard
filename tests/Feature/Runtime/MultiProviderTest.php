<?php

namespace Tests\Feature\Runtime;

use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\User;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Models\RuntimeSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Multi-provider tiers: ChatGPT (OpenAI) and Gemini (Google) agents run
 * through their own clients with canonical-format translation; tiers
 * whose provider key is missing are not selectable.
 */
class MultiProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'runtime.llm.anthropic.api_key' => 'sk-ant-test',
            'runtime.llm.openai.api_key' => 'sk-oai-test',
            'runtime.llm.google.api_key' => 'sk-gem-test',
        ]);
    }

    public function test_gpt_agent_routes_to_openai_with_the_configured_model(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'GPT says hi'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 10],
            ]),
        ]);

        [$user, $agent] = $this->agentOnTier('gpt');

        $traces = app(Runtime::class)->launch($agent, 'v1');

        $this->assertSame('GPT says hi', $traces[0]['payload']['message']);
        Http::assertSent(function (Request $r): bool {
            return str_contains($r->url(), 'api.openai.com/v1/chat/completions')
                && $r['model'] === config('runtime.tiers.gpt.model')
                && $r['messages'][0]['role'] === 'system';
        });
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'anthropic'));
    }

    public function test_openai_tool_calls_round_trip_and_capture_a_lead(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => ['name' => 'capture_lead', 'arguments' => '{"name":"Bob","email":"bob@x.co"}'],
                            ]],
                        ],
                        'finish_reason' => 'tool_calls',
                    ]],
                    'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 20],
                ])
                ->push([
                    'choices' => [['message' => ['content' => 'Saved, Bob!'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 60, 'completion_tokens' => 8],
                ]),
        ]);

        [$user, $agent] = $this->agentOnTier('gpt', state: 'discovery');

        $traces = app(Runtime::class)->sendText($agent, 'v1', 'I am Bob, bob@x.co');

        $this->assertStringContainsString('Saved, Bob', $traces[0]['payload']['message']);
        $this->assertDatabaseHas('leads', ['email' => 'bob@x.co', 'agent_id' => $agent->id]);

        // The second call must carry the tool result as a role:tool message,
        // and the replayed assistant tool-call turn must serialize with a
        // string content — never null. OpenAI tolerates null alongside
        // tool_calls, but stricter compatible backends (Ollama) reject it
        // with "invalid message content type: <nil>".
        Http::assertSent(function (Request $r): bool {
            $sawToolResult = false;
            foreach ((array) $r['messages'] as $m) {
                if (($m['role'] ?? '') === 'tool' && ($m['tool_call_id'] ?? '') === 'call_1') {
                    $sawToolResult = true;
                }
                if (($m['role'] ?? '') === 'assistant' && isset($m['tool_calls'])) {
                    if (! is_string($m['content'] ?? null)) {
                        return false;
                    }
                }
            }

            return $sawToolResult;
        });
    }

    public function test_gemini_agent_routes_to_google_and_replies(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Gemini here!']]]]],
                'usageMetadata' => ['promptTokenCount' => 25, 'candidatesTokenCount' => 6],
            ]),
        ]);

        [$user, $agent] = $this->agentOnTier('gemini');

        $traces = app(Runtime::class)->launch($agent, 'v1');

        $this->assertSame('Gemini here!', $traces[0]['payload']['message']);
        Http::assertSent(function (Request $r): bool {
            return str_contains($r->url(), 'generativelanguage.googleapis.com')
                && str_contains($r->url(), rawurlencode((string) config('runtime.tiers.gemini.model')))
                && isset($r['systemInstruction']);
        });
    }

    public function test_gemini_function_calls_round_trip_by_name(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'candidates' => [['content' => ['role' => 'model', 'parts' => [
                        ['functionCall' => ['name' => 'capture_lead', 'args' => ['name' => 'Eve', 'email' => 'eve@x.co']]],
                    ]]]],
                    'usageMetadata' => ['promptTokenCount' => 30, 'candidatesTokenCount' => 12],
                ])
                ->push([
                    'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Done, Eve!']]]]],
                    'usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 5],
                ]),
        ]);

        [$user, $agent] = $this->agentOnTier('gemini', state: 'discovery');

        $traces = app(Runtime::class)->sendText($agent, 'v1', 'eve@x.co here');

        $this->assertStringContainsString('Done, Eve', $traces[0]['payload']['message']);
        $this->assertDatabaseHas('leads', ['email' => 'eve@x.co']);

        // Second call carries a functionResponse part keyed by NAME.
        Http::assertSent(function (Request $r): bool {
            foreach ((array) ($r['contents'] ?? []) as $content) {
                foreach ((array) ($content['parts'] ?? []) as $part) {
                    if (($part['functionResponse']['name'] ?? '') === 'capture_lead') {
                        return true;
                    }
                }
            }

            return false;
        });
    }

    public function test_usage_rollups_are_split_per_tier_row(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'hi']]]]],
                'usageMetadata' => ['promptTokenCount' => 25, 'candidatesTokenCount' => 6],
            ]),
        ]);

        [$user, $agent] = $this->agentOnTier('gemini');
        app(Runtime::class)->launch($agent, 'v1');

        $this->assertDatabaseHas('runtime_usage', [
            'team_id' => $user->currentTeam->id,
            'tier' => 'gemini',
            'tokens_in' => 25,
            'tokens_out' => 6,
        ]);
    }

    public function test_a_premium_tier_is_not_selectable_without_the_teams_own_key(): void
    {
        config(['runtime.llm.google.api_key' => '']); // our key is irrelevant to BYOK

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();
        $user = $user->fresh();

        // Validation: can't save a draft on an unavailable tier.
        $this->actingAs($user)->post(route('agents.versions.draft'), [
            'instructions' => '', 'greeting' => '', 'model_tier' => 'gemini',
        ])->assertSessionHasErrors('model_tier');

        // UI flag: gemini is shown but not selectable without a key; Core is.
        $this->actingAs($user)->get(route('agents.versions.index'))
            ->assertInertia(fn ($page) => $page
                ->where('tiers.4.key', 'gemini')
                ->where('tiers.4.byok_only', true)
                ->where('tiers.4.selectable', false)
                ->where('tiers.3.key', 'gpt')
                ->where('tiers.3.selectable', true)
            );
    }

    public function test_gpt_tier_bills_two_credits_per_embed_turn(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'hi'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
        ]);

        [$user, $agent] = $this->agentOnTier('gpt', state: 'discovery');
        $user->currentTeam->forceFill(['credit_balance' => 100])->save();

        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-v1',
            'message' => 'hello',
        ])->assertOk();

        // (1 + 1 reply) × gpt multiplier 1 = 2 credits (nano-backed budget tier).
        $this->assertSame(98, $user->currentTeam->fresh()->credit_balance);
    }

    /**
     * @return array{0: User, 1: Agent}
     */
    private function agentOnTier(string $tier, string $state = 'greeting'): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id, 'credit_balance' => 100])->save();

        // Premium engines are BYOK-only, so routing to one means the team has
        // connected a key for its provider.
        if ((bool) config("runtime.tiers.{$tier}.byok_only", false)) {
            $this->grantOwnKey($user->currentTeam, (string) config("runtime.tiers.{$tier}.provider"));
            $user->currentTeam->forceFill(['credit_balance' => 100])->save();
        }

        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => 'published',
            'config' => ['instructions' => '', 'greeting' => '', 'model_tier' => $tier],
            'published_at' => now(),
        ]);

        if ($state !== 'greeting') {
            RuntimeSession::create([
                'agent_id' => $agent->id, 'visitor_id' => 'v1', 'flow_state' => $state,
                'variables' => [], 'history' => [],
            ]);
        }

        return [$user->fresh(), $agent];
    }
}
