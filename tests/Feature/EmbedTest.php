<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\User;
use App\Notifications\HandoffRequestedNotification;
use App\Runtime\Models\RuntimeSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmbedTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_js_renders_for_active_agent(): void
    {
        $agent = $this->makeAgent('active');

        $response = $this->get("/widget/{$agent->slug}.js")->assertOk();
        $this->assertSame('application/javascript; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("/embed/{$agent->slug}", $response->getContent());
        $this->assertStringContainsString('fs-embed-btn', $response->getContent());
    }

    public function test_widget_js_404s_for_unknown_or_inactive_agent(): void
    {
        $this->get('/widget/team-fake-9999.js')->assertNotFound();

        $draft = $this->makeAgent('draft');
        $this->get("/widget/{$draft->slug}.js")->assertNotFound();
    }

    public function test_embed_chat_page_renders_for_active_agent(): void
    {
        $agent = $this->makeAgent('active');

        $this->get("/embed/{$agent->slug}")
            ->assertOk()
            ->assertSee($agent->name)
            ->assertSee('Type a message');
    }

    public function test_embed_chat_page_allows_iframe_embedding(): void
    {
        $agent = $this->makeAgent('active');

        $response = $this->get("/embed/{$agent->slug}");
        $this->assertStringContainsString(
            'frame-ancestors *',
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_embed_launch_creates_visitor_and_returns_traces(): void
    {
        $agent = $this->makeAgent('active');

        // Fake the LLM at the network layer.
        config(['runtime.llm.anthropic.api_key' => 'sk-test']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Welcome!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ], 200),
        ]);

        $response = $this->postJson("/embed/{$agent->slug}/launch");

        $response->assertOk()
            ->assertJsonStructure(['visitor_id', 'agent_name', 'traces'])
            ->assertJsonPath('agent_name', $agent->name);
    }

    public function test_embed_launch_404s_for_inactive_agent(): void
    {
        $draft = $this->makeAgent('draft');

        $this->postJson("/embed/{$draft->slug}/launch")->assertNotFound();
    }

    public function test_embed_interact_validates_required_fields(): void
    {
        $agent = $this->makeAgent('active');

        $this->postJson("/embed/{$agent->slug}/interact", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['visitor_id', 'message']);
    }

    public function test_embed_interact_402s_when_team_out_of_credits(): void
    {
        $agent = $this->makeAgent('active');
        $agent->team->forceFill(['credit_balance' => 0])->save();

        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-test',
            'message' => 'Hello',
        ])->assertStatus(402);
    }

    public function test_embed_interact_succeeds_and_debits_credits(): void
    {
        $agent = $this->makeAgent('active');
        $agent->team->forceFill(['credit_balance' => 100])->save();

        config(['runtime.llm.anthropic.api_key' => 'sk-test']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Got it!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ], 200),
        ]);

        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-test',
            'message' => 'Hello',
        ])->assertOk()
            ->assertJsonStructure(['traces']);

        // (1 user message + 1 reply) × haiku multiplier 11 = 22 credits.
        $this->assertSame(78, $agent->team->fresh()->credit_balance);
    }

    public function test_canned_answer_short_circuits_without_llm_or_credits(): void
    {
        $agent = $this->makeAgent('active');
        $agent->team->forceFill(['credit_balance' => 100])->save();
        $this->publishCanned($agent, [
            ['category' => 'Pricing', 'keywords' => ['cost', 'how much'], 'answer' => 'Plans start at $99/mo.'],
        ]);

        // No Anthropic fake on purpose — a canned hit must never touch the LLM.
        Http::fake();

        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-canned',
            'message' => 'how much does it cost?',
        ])->assertOk()
            ->assertJsonPath('traces.0.payload.message', 'Plans start at $99/mo.')
            ->assertJsonPath('traces.0.payload.canned', true);

        Http::assertNothingSent();
        // FAQ turns are free — balance is untouched.
        $this->assertSame(100, $agent->team->fresh()->credit_balance);
    }

    public function test_canned_answer_never_repeats_within_a_conversation(): void
    {
        $agent = $this->makeAgent('active');
        $agent->team->forceFill(['credit_balance' => 100])->save();
        $this->publishCanned($agent, [
            ['category' => 'Pricing', 'keywords' => ['cost', 'how much'], 'answer' => 'Plans start at $99/mo.'],
        ]);

        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-canned-repeat',
            'message' => 'how much does it cost?',
        ])->assertOk()->assertJsonPath('traces.0.payload.canned', true);

        // The visitor's follow-up repeats the trigger keyword; serving the
        // identical paragraph again reads as ignoring them — this turn must
        // fall through to the LLM instead.
        config(['runtime.llm.anthropic.api_key' => 'sk-test']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'It depends on the tier you pick.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ], 200),
        ]);
        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-canned-repeat',
            'message' => 'ok but how much does it cost for my use case?',
        ])->assertOk()->assertJsonPath('traces.0.payload.canned', null);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'anthropic'));
    }

    public function test_escalating_canned_answer_notifies_owner_and_flags_handoff(): void
    {
        Notification::fake();
        $agent = $this->makeAgent('active');
        $agent->team->forceFill(['credit_balance' => 100])->save();
        $this->publishCanned($agent, [
            ['category' => 'Talk to a human', 'keywords' => ['human'], 'answer' => 'A teammate is on the way.', 'escalate' => true],
        ]);

        Http::fake();

        // The runtime session the escalation hook flags (normally created by
        // launch, which would need a greeting or an LLM in this fixture).
        RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => 'embed-esc',
            'flow_state' => 'discovery',
            'variables' => [],
            'history' => [],
            'last_activity_at' => now(),
        ]);
        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-esc',
            'message' => 'human please',
        ])->assertOk()
            ->assertJsonPath('traces.0.payload.canned', true)
            ->assertJsonPath('handoff', true);

        Notification::assertSentTo($agent->team->owner, HandoffRequestedNotification::class);
        // Zero LLM calls, zero credits — still a free canned turn.
        $this->assertSame(100, $agent->team->fresh()->credit_balance);
    }

    public function test_canned_answer_served_even_when_out_of_credits(): void
    {
        $agent = $this->makeAgent('active');
        $agent->team->forceFill(['credit_balance' => 0])->save();
        $this->publishCanned($agent, [
            ['category' => 'Pricing', 'keywords' => ['cost'], 'answer' => 'Plans start at $99/mo.'],
        ]);
        Http::fake();

        // The typed path would 402 out of credits; a canned hit answers anyway.
        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-canned-2',
            'message' => 'Pricing',
        ])->assertOk()
            ->assertJsonPath('traces.0.payload.canned', true);

        Http::assertNothingSent();
    }

    public function test_non_matching_message_falls_through_to_the_llm(): void
    {
        $agent = $this->makeAgent('active');
        $agent->team->forceFill(['credit_balance' => 100])->save();
        $this->publishCanned($agent, [
            ['category' => 'Pricing', 'keywords' => ['cost'], 'answer' => 'From $99.'],
        ]);

        config(['runtime.llm.anthropic.api_key' => 'sk-test']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'We integrate with Slack!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ], 200),
        ]);

        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-fallthrough',
            'message' => 'do you integrate with Slack?',
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'anthropic'));
        // LLM turn billed normally: (1 + 1) × 11.
        $this->assertSame(78, $agent->team->fresh()->credit_balance);
    }

    public function test_launch_exposes_canned_chips(): void
    {
        $agent = $this->makeAgent('active');
        $this->publishCanned($agent, [
            ['category' => 'Pricing', 'keywords' => ['cost'], 'answer' => 'From $99.'],
            ['category' => 'Features', 'keywords' => ['feature'], 'answer' => 'Lots.'],
        ]);

        config(['runtime.llm.anthropic.api_key' => 'sk-test']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hi!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], 200),
        ]);

        $this->postJson("/embed/{$agent->slug}/launch", ['visitor_id' => 'embed-chips'])
            ->assertOk()
            ->assertJsonPath('chips', ['Pricing', 'Features']);
    }

    /** @param list<array<string, mixed>> $canned */
    private function publishCanned(Agent $agent, array $canned): void
    {
        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => 'published',
            'config' => ['canned_answers' => $canned],
            'published_at' => now(),
        ]);
    }

    private function makeAgent(string $status): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['credit_balance' => 1000])->save();

        return Agent::factory()->for($team)->create([
            'status' => $status,
        ]);
    }
}
