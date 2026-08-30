<?php

namespace Tests\Feature;

use App\Billing\OwnKey;
use App\Billing\Plan;
use App\Models\Agent;
use App\Models\TeamProviderKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every surface that says "credits" must know when the team's own key is
 * carrying the cost: the shared billing prop (sidebar meter) and the
 * Versions page's tier tiles.
 */
class OwnKeyAwarenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_billing_prop_and_tier_tiles_without_a_key(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        $this->actingAs($user)
            ->get(route('agents.versions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('billing.own_key.active', false)
                ->where('billing.own_key.has_key', false)
                ->where('tiers.0.own_key', false)
            );
    }

    public function test_shared_billing_prop_and_matching_tier_tiles_with_a_covering_key(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => Plan::Pro->value])->save();
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        $provider = app(OwnKey::class)->providerFor($agent);
        TeamProviderKey::create([
            'team_id' => $team->id,
            'provider' => $provider,
            'api_key' => 'sk-test-'.str_repeat('x', 20),
            'key_hint' => '…xxxx',
            'last_verified_at' => now(),
            'last_error' => null,
        ]);

        $response = $this->actingAs($user)->get(route('agents.versions.index'))->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('billing.own_key.active', true)
            ->where('billing.own_key.cap', Plan::Pro->monthlyMessageCap())
        );

        // Only tiers on the key's provider are free; the others still bill.
        $tiers = $response->inertiaProps()['tiers'];
        foreach ($tiers as $tier) {
            $this->assertSame($tier['provider'] === $provider, $tier['own_key'], "tier {$tier['key']} own_key flag");
        }
        $this->assertTrue(collect($tiers)->contains('own_key', true));
        $this->assertTrue(collect($tiers)->contains('own_key', false));
    }
}
