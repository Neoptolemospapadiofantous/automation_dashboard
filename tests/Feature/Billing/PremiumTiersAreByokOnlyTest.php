<?php

namespace Tests\Feature\Billing;

use App\Billing\OwnKey;
use App\Billing\Plan;
use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\TeamProviderKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Founder call 2026-09-02: platform credits buy Flowstack Core and nothing
 * else. Every premium engine runs on the customer's own provider key, which
 * is available above Starter.
 */
class PremiumTiersAreByokOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Mirror production, which serves Flowstack Core by default.
        config(['runtime.default_tier' => 'gpt']);
    }

    private function agentOn(string $tier, Plan $plan, bool $withKey = false): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => $plan->value])->save();
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        AgentConfigVersion::create([
            'agent_id' => $agent->id, 'version' => 1, 'status' => 'published',
            'config' => ['instructions' => '', 'greeting' => '', 'model_tier' => $tier],
            'published_at' => now(),
        ]);

        if ($withKey) {
            TeamProviderKey::create([
                'team_id' => $team->id,
                'provider' => (string) config("runtime.tiers.{$tier}.provider"),
                'api_key' => 'sk-test-'.str_repeat('x', 20),
                'key_hint' => '…xxxx',
                'last_verified_at' => now(),
            ]);
        }

        return $agent->fresh();
    }

    public function test_byok_is_available_above_starter_only(): void
    {
        $this->assertFalse(Plan::Free->allowsOwnKey());
        $this->assertFalse(Plan::Starter->allowsOwnKey(), 'Starter is Core-only');
        $this->assertTrue(Plan::Growth->allowsOwnKey());
        $this->assertTrue(Plan::Pro->allowsOwnKey());
        $this->assertSame(10_000, Plan::Growth->monthlyMessageCap());
    }

    public function test_a_premium_tier_without_a_key_runs_and_bills_as_core(): void
    {
        $agent = $this->agentOn('sonnet', Plan::Growth); // no key connected
        $ownKey = app(OwnKey::class);

        // The customer's choice is preserved…
        $this->assertSame('sonnet', AgentConfigVersion::publishedTier($agent->id));
        // …but the turn runs, and is billed, as Core.
        $this->assertSame('gpt', $ownKey->effectiveTier($agent));
        $this->assertSame(1, $ownKey->creditsForChat($agent));
        $this->assertSame(1, $ownKey->effectiveCreditsPerMessage($agent));
    }

    public function test_a_premium_tier_with_a_key_runs_on_that_key_for_no_credits(): void
    {
        $agent = $this->agentOn('sonnet', Plan::Growth, withKey: true);
        $ownKey = app(OwnKey::class);

        $this->assertSame('sonnet', $ownKey->effectiveTier($agent));
        $this->assertSame(0, $ownKey->creditsForChat($agent), 'their key, their provider bill');
    }

    public function test_starter_cannot_reach_a_premium_tier_even_holding_a_key(): void
    {
        $agent = $this->agentOn('opus', Plan::Starter, withKey: true);

        $this->assertSame('gpt', app(OwnKey::class)->effectiveTier($agent));
    }

    public function test_core_is_unaffected_and_still_bills_platform_credits(): void
    {
        $agent = $this->agentOn('gpt', Plan::Free);
        $ownKey = app(OwnKey::class);

        $this->assertSame('gpt', $ownKey->effectiveTier($agent));
        $this->assertSame(1, $ownKey->creditsForChat($agent));
        $this->assertSame('Flowstack Core', config('runtime.tiers.gpt.label'));
        $this->assertFalse((bool) config('runtime.tiers.gpt.byok_only'));
    }

    public function test_every_premium_tier_is_flagged_byok_only(): void
    {
        foreach (['haiku', 'sonnet', 'opus', 'gemini'] as $tier) {
            $this->assertTrue(
                (bool) config("runtime.tiers.{$tier}.byok_only"),
                "Tier '{$tier}' must be BYOK-only — platform credits do not buy premium engines.",
            );
        }
    }

    public function test_the_versions_page_refuses_a_premium_tier_without_a_key(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);
        $user->currentTeam->forceFill(['plan' => Plan::Growth->value, 'current_agent_id' => $agent->id])->save();

        $this->actingAs($user)->post(route('agents.versions.draft'), [
            'instructions' => '', 'greeting' => '', 'model_tier' => 'sonnet',
        ])->assertSessionHasErrors('model_tier');
    }

    public function test_onboarding_refuses_a_premium_tier(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->post(route('onboarding.start'), [
            'name' => 'Bot', 'model_tier' => 'opus',
        ])->assertSessionHasErrors('model_tier');
    }

    public function test_the_free_website_build_is_annual_only_and_operator_only(): void
    {
        // The promotion the landing sells: annual Operator includes the
        // brochure-style website build. Locked here because the plan cards
        // render for BOTH cycles, so a surface that drops the annual
        // condition promises a monthly subscriber something they do not get.
        $this->assertTrue(Plan::Pro->includesWebsiteBuildOnAnnual());

        foreach ([Plan::Free, Plan::Starter, Plan::Growth, Plan::Business] as $plan) {
            $this->assertFalse(
                $plan->includesWebsiteBuildOnAnnual(),
                $plan->value.' must not advertise the free website build',
            );
        }
    }
}
