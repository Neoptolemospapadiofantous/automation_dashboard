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

    public function test_default_agent_uses_the_haiku_model(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;

        app(Runtime::class)->launch($agent, 'v1');

        Http::assertSent(fn (Request $r): bool => $r['model'] === config('runtime.tiers.haiku.model'));
    }

    public function test_sonnet_agent_uses_the_sonnet_model(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        $this->publishTier($agent, 'sonnet');

        app(Runtime::class)->launch($agent, 'v1');

        Http::assertSent(fn (Request $r): bool => $r['model'] === config('runtime.tiers.sonnet.model'));
    }

    public function test_dashboard_chat_bills_the_tier_multiplier(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        $this->publishTier($agent, 'sonnet'); // 3 credits/message
        $user->currentTeam->forceFill(['credit_balance' => 100])->save();

        $this->actingAs($user)->postJson(route('chat.launch'))->assertOk();

        // (1 user-equivalent + 1 reply) × 3 = 6 credits.
        $this->assertSame(94, $user->currentTeam->fresh()->credit_balance);
    }

    public function test_embed_interact_bills_per_reply_times_multiplier(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        $this->publishTier($agent, 'sonnet');
        $user->currentTeam->forceFill(['credit_balance' => 100])->save();

        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-v1',
            'message' => 'hello',
        ])->assertOk();

        $this->assertSame(94, $user->currentTeam->fresh()->credit_balance); // (1 + 1 reply) × 3
    }

    public function test_haiku_agent_bills_two_credits_per_embed_turn(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        $user->currentTeam->forceFill(['credit_balance' => 100])->save();

        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-v1',
            'message' => 'hello',
        ])->assertOk();

        $this->assertSame(98, $user->currentTeam->fresh()->credit_balance); // (1 + 1 reply) × 1
    }

    public function test_sonnet_tokens_land_in_a_sonnet_tier_row(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        $this->publishTier($agent, 'sonnet');

        app(Runtime::class)->launch($agent, 'v1');

        $row = RuntimeUsage::where('team_id', $user->currentTeam->id)->first();
        $this->assertSame('sonnet', $row->tier);
        $this->assertSame(100, $row->tokens_in);
        $this->assertSame(50, $row->tokens_out);
    }

    public function test_unknown_tier_degrades_to_haiku(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        AgentConfigVersion::create([
            'agent_id' => $agent->id, 'version' => 1, 'status' => 'published',
            'config' => ['instructions' => '', 'greeting' => '', 'model_tier' => 'platinum-turbo'],
            'published_at' => now(),
        ]);

        $this->assertSame('haiku', AgentConfigVersion::publishedTier($agent->id));
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
        $this->publishTier($enhancedAgent, 'sonnet');

        $standardAgent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);

        $this->assertSame(3, AgentConfigVersion::creditsPerMessage($enhancedAgent->id));
        $this->assertSame(1, AgentConfigVersion::creditsPerMessage($standardAgent->id));
    }

    public function test_onboarding_with_sonnet_tier_seeds_published_config(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->post(route('onboarding.start'), [
            'name' => 'Closer bot',
            'model_tier' => 'sonnet',
        ])->assertRedirect(route('onboarding.done'));

        $agent = $user->currentTeam->fresh()->currentAgent;
        $this->assertSame('sonnet', AgentConfigVersion::publishedTier($agent->id));
        $this->assertSame(3, AgentConfigVersion::creditsPerMessage($agent->id));
    }

    public function test_onboarding_with_haiku_tier_seeds_starter_config(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->post(route('onboarding.start'), [
            'name' => 'FAQ bot',
            'model_tier' => 'haiku',
        ])->assertRedirect(route('onboarding.done'));

        // Every fresh agent gets a published v1: goal-shaped starter
        // instructions + a static greeting (served without an LLM call).
        // Haiku stays the default tier at 1 credit per message.
        $this->assertSame(1, AgentConfigVersion::count());
        $agent = $user->currentTeam->fresh()->currentAgent;
        $config = AgentConfigVersion::publishedConfig($agent->id);
        $this->assertNotSame('', (string) $config['instructions']);
        $this->assertNotSame('', (string) $config['greeting']);
        $this->assertSame(1, AgentConfigVersion::creditsPerMessage($agent->id));
    }

    public function test_onboarding_reclick_does_not_overwrite_tier(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->post(route('onboarding.start'), ['model_tier' => 'sonnet']);
        // Double-submit with a different tier: existing agent is reused,
        // its config must NOT be touched.
        $this->actingAs($user)->post(route('onboarding.start'), ['model_tier' => 'haiku']);

        $agent = $user->currentTeam->fresh()->currentAgent;
        $this->assertSame('sonnet', AgentConfigVersion::publishedTier($agent->id));
        $this->assertSame(1, AgentConfigVersion::count());
    }

    public function test_onboarding_rejects_bogus_tier(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->post(route('onboarding.start'), [
            'model_tier' => 'gpt-5-ultra',
        ])->assertSessionHasErrors('model_tier');
    }

    public function test_opus_agent_uses_opus_model_and_bills_ten(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        $this->publishTier($agent, 'opus');
        $user->currentTeam->forceFill(['credit_balance' => 100])->save();

        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-v1',
            'message' => 'hello',
        ])->assertOk();

        Http::assertSent(fn (Request $r): bool => $r['model'] === config('runtime.tiers.opus.model'));
        $this->assertSame(80, $user->currentTeam->fresh()->credit_balance); // (1 + 1 reply) × 10

        $row = RuntimeUsage::where('team_id', $user->currentTeam->id)->first();
        $this->assertSame('opus', $row->tier);
        $this->assertSame(100, $row->tokens_in);
        $this->assertSame(50, $row->tokens_out);
    }

    public function test_legacy_tier_keys_alias_to_the_lineup(): void
    {
        // Rows published before the full lineup used standard/enhanced —
        // they must keep resolving without a data migration.
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        $this->publishTier($agent, 'enhanced');

        $this->assertSame('sonnet', AgentConfigVersion::publishedTier($agent->id));
        $this->assertSame(3, AgentConfigVersion::creditsPerMessage($agent->id));
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
