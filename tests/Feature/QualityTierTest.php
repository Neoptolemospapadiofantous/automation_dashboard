<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\RuntimeUsage;
use App\Models\User;
use App\Runtime\Contracts\Runtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Per-agent quality tiers: the published model_tier picks the Anthropic
 * model AND the credit price — coupled so smarter models stay
 * margin-safe by construction.
 */
class QualityTierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['runtime.llm.anthropic.api_key' => 'sk-test']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Reply!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ]),
        ]);
    }

    public function test_default_agent_uses_the_standard_model(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;

        app(Runtime::class)->launch($agent, 'v1');

        Http::assertSent(fn (Request $r): bool => $r['model'] === config('runtime.tiers.standard.model'));
    }

    public function test_enhanced_agent_uses_the_enhanced_model(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        $this->publishTier($agent, 'enhanced');

        app(Runtime::class)->launch($agent, 'v1');

        Http::assertSent(fn (Request $r): bool => $r['model'] === config('runtime.tiers.enhanced.model'));
    }

    public function test_dashboard_chat_bills_the_tier_multiplier(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        $this->publishTier($agent, 'enhanced'); // 3 credits/message
        $user->currentTeam->forceFill(['credit_balance' => 100])->save();

        $this->actingAs($user)->postJson(route('chat.launch'))->assertOk();

        // (1 user-equivalent + 1 reply) × 3 = 6 credits.
        $this->assertSame(94, $user->currentTeam->fresh()->credit_balance);
    }

    public function test_embed_interact_debits_the_tier_multiplier(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        $this->publishTier($agent, 'enhanced');
        $user->currentTeam->forceFill(['credit_balance' => 100])->save();

        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-v1',
            'message' => 'hello',
        ])->assertOk();

        $this->assertSame(97, $user->currentTeam->fresh()->credit_balance); // 1 × 3
    }

    public function test_standard_agent_still_bills_one_credit_on_embed(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        $user->currentTeam->forceFill(['credit_balance' => 100])->save();

        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-v1',
            'message' => 'hello',
        ])->assertOk();

        $this->assertSame(99, $user->currentTeam->fresh()->credit_balance);
    }

    public function test_enhanced_tokens_land_in_the_enhanced_usage_bucket(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        $this->publishTier($agent, 'enhanced');

        app(Runtime::class)->launch($agent, 'v1');

        $row = RuntimeUsage::where('team_id', $user->currentTeam->id)->first();
        $this->assertSame(100, $row->tokens_in_enhanced);
        $this->assertSame(50, $row->tokens_out_enhanced);
        $this->assertSame(0, $row->tokens_in);
        $this->assertSame(0, $row->tokens_out);
    }

    public function test_unknown_tier_degrades_to_standard(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        AgentConfigVersion::create([
            'agent_id' => $agent->id, 'version' => 1, 'status' => 'published',
            'config' => ['instructions' => '', 'greeting' => '', 'model_tier' => 'platinum-turbo'],
            'published_at' => now(),
        ]);

        $this->assertSame('standard', AgentConfigVersion::publishedTier($agent->id));
        $this->assertSame(1, AgentConfigVersion::creditsPerMessage($agent->id));
    }

    public function test_save_draft_rejects_bogus_tier(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->post(route('agents.versions.draft'), [
            'instructions' => '', 'greeting' => '', 'model_tier' => 'gpt-5',
        ])->assertSessionHasErrors('model_tier');
    }

    public function test_tier_is_per_agent_not_per_team(): void
    {
        $user = $this->owner();
        $enhancedAgent = $user->currentTeam->currentAgent;
        $this->publishTier($enhancedAgent, 'enhanced');

        $standardAgent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);

        $this->assertSame(3, AgentConfigVersion::creditsPerMessage($enhancedAgent->id));
        $this->assertSame(1, AgentConfigVersion::creditsPerMessage($standardAgent->id));
    }

    public function test_onboarding_with_enhanced_tier_seeds_published_config(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->post(route('onboarding.start'), [
            'name' => 'Closer bot',
            'model_tier' => 'enhanced',
        ])->assertRedirect(route('onboarding.done'));

        $agent = $user->currentTeam->fresh()->currentAgent;
        $this->assertSame('enhanced', AgentConfigVersion::publishedTier($agent->id));
        $this->assertSame(3, AgentConfigVersion::creditsPerMessage($agent->id));
    }

    public function test_onboarding_with_standard_tier_seeds_nothing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->post(route('onboarding.start'), [
            'name' => 'FAQ bot',
            'model_tier' => 'standard',
        ])->assertRedirect(route('onboarding.done'));

        // Standard is the default — no config row, so the dashboard
        // checklist's 'Publish behavior' step stays meaningful.
        $this->assertSame(0, AgentConfigVersion::count());
        $agent = $user->currentTeam->fresh()->currentAgent;
        $this->assertSame(1, AgentConfigVersion::creditsPerMessage($agent->id));
    }

    public function test_onboarding_reclick_does_not_overwrite_tier(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->post(route('onboarding.start'), ['model_tier' => 'enhanced']);
        // Double-submit with a different tier: existing agent is reused,
        // its config must NOT be touched.
        $this->actingAs($user)->post(route('onboarding.start'), ['model_tier' => 'standard']);

        $agent = $user->currentTeam->fresh()->currentAgent;
        $this->assertSame('enhanced', AgentConfigVersion::publishedTier($agent->id));
        $this->assertSame(1, AgentConfigVersion::count());
    }

    public function test_onboarding_rejects_bogus_tier(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->post(route('onboarding.start'), [
            'model_tier' => 'gpt-5-ultra',
        ])->assertSessionHasErrors('model_tier');
    }

    private function publishTier(Agent $agent, string $tier): void
    {
        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => 'published',
            'config' => ['instructions' => '', 'greeting' => '', 'model_tier' => $tier],
            'published_at' => now(),
        ]);
    }

    private function owner(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id, 'credit_balance' => 100])->save();

        return $user->fresh();
    }
}
