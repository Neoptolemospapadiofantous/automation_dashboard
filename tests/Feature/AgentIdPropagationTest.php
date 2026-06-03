<?php

namespace Tests\Feature;

use App\Enums\AssignmentStrategy;
use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadDelegator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the agent_id-propagation fixes identified in the post-Phase-D
 * audit. Without these, any row created outside the multi-tenant happy
 * path (manual lead create from the kanban, programmatic delegation,
 * transcript backfill) lands with agent_id = NULL and is invisible to
 * Phase G's agent-scoped queries.
 */
class AgentIdPropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_controller_store_stamps_current_agent_id(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        $this->actingAs($user->fresh())
            ->post(route('leads.store'), [
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('leads', [
            'team_id' => $user->currentTeam->id,
            'agent_id' => $agent->id,
            'email' => 'ada@example.com',
        ]);
    }

    public function test_lead_controller_store_handles_team_with_no_current_agent(): void
    {
        // A team with no current_agent_id (rare — only happens pre-onboarding)
        // should still let leads be created; agent_id just lands null.
        // The middleware would normally bounce the user to onboarding before
        // this is reachable, but the data layer must not crash if reached.
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->post(route('leads.store'), [
                'name' => 'Grace Hopper',
                'email' => 'grace@example.com',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('leads', [
            'team_id' => $user->currentTeam->id,
            'agent_id' => null,
            'email' => 'grace@example.com',
        ]);
    }

    public function test_lead_delegator_stamps_agent_id_on_audit_row(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $lead = Lead::factory()->create([
            'team_id' => $user->currentTeam->id,
            'agent_id' => $agent->id,
        ]);

        app(LeadDelegator::class)->assign(
            lead: $lead,
            strategy: AssignmentStrategy::Manual,
            byUser: $user,
            toUserId: $user->id,
        );

        $this->assertDatabaseHas('lead_assignments', [
            'lead_id' => $lead->id,
            'team_id' => $user->currentTeam->id,
            'agent_id' => $agent->id,
            'assigned_to' => $user->id,
        ]);
    }
}
