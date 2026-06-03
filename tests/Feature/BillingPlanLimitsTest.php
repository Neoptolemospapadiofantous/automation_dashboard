<?php

namespace Tests\Feature;

use App\Actions\Agents\CreateAgent;
use App\Billing\Exceptions\PlanLimitExceeded;
use App\Billing\Plan;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan limit enforcement: the CreateAgent action refuses when the team is
 * at its plan's max_agents cap. AgentController catches this and bounces
 * to /agents with a flash message the UI can render as an upgrade CTA.
 */
class BillingPlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_plan_caps_agents_at_one(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        // Team is on Free (factory default). Free's maxAgents = 1.
        // Create the first agent — should succeed.
        (new CreateAgent())->execute($user->currentTeam, 'First');

        // Second one should throw.
        $this->expectException(PlanLimitExceeded::class);
        (new CreateAgent())->execute($user->currentTeam, 'Second');
    }

    public function test_pro_plan_caps_at_five(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $user->currentTeam->forceFill(['plan' => Plan::Pro->value])->save();

        for ($i = 1; $i <= 5; $i++) {
            (new CreateAgent())->execute($user->currentTeam->fresh(), "Agent {$i}");
        }

        $this->expectException(PlanLimitExceeded::class);
        (new CreateAgent())->execute($user->currentTeam->fresh(), 'Agent 6');
    }

    public function test_business_plan_does_not_cap(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $user->currentTeam->forceFill(['plan' => Plan::Business->value])->save();

        // Just confirm creating many doesn't throw — we won't actually
        // make 1000 to keep the test fast.
        for ($i = 1; $i <= 10; $i++) {
            (new CreateAgent())->execute($user->currentTeam->fresh(), "Agent {$i}");
        }

        $this->assertSame(10, $user->currentTeam->agents()->count());
    }

    public function test_agent_controller_store_flashes_plan_limit_on_cap(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        // Already at Free's cap (1 agent).
        Agent::factory()->for($user->currentTeam)->create();

        // Now handled by the global exception renderer in bootstrap/app.php.
        // Web requests get back()->with('flash.plan_limit', ...). The exact
        // redirect target is `back()` which depends on the Referer header
        // (defaults to '/' in tests), so we don't assert the destination —
        // only that we got a redirect with the flash payload.
        $response = $this->actingAs($user->fresh())
            ->post(route('agents.store'), ['name' => 'Second bot']);

        $response->assertRedirect();
        $response->assertSessionHas('flash.plan_limit');
    }

    public function test_exception_carries_plan_metadata(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Agent::factory()->for($user->currentTeam)->create();

        try {
            (new CreateAgent())->execute($user->currentTeam->fresh(), 'Second');
            $this->fail('Expected PlanLimitExceeded');
        } catch (PlanLimitExceeded $e) {
            $this->assertSame('agents', $e->resource);
            $this->assertSame(1, $e->limit);
            $this->assertSame(Plan::Free, $e->plan);
        }
    }
}
