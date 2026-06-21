<?php

namespace Tests\Feature;

use App\Enums\AssignmentStrategy;
use App\Enums\LeadStatus;
use App\Events\Domain\LeadAssigned;
use App\Events\Domain\LeadQualified;
use App\Events\Domain\LeadStatusChanged;
use App\Events\Domain\StateChanged;
use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadDelegator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Verifies the production code paths (controllers, services) now route
 * status changes through the state machine — before audit fix 1, every
 * status mutation went around it and the typed events never fired in prod.
 */
class StateMachineWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_kanban_drag_drop_fires_typed_events(): void
    {
        Event::fake([LeadStatusChanged::class, LeadQualified::class, StateChanged::class]);

        $user = User::factory()->withPersonalTeam()->create();
        $lead = Lead::factory()->status(LeadStatus::New)->create([
            'team_id' => $user->currentTeam->id,
        ]);

        $this->actingAs($user)
            ->patch("/leads/{$lead->id}/status", ['status' => LeadStatus::Qualified->value])
            ->assertRedirect();

        $this->assertSame(LeadStatus::Qualified, $lead->fresh()->status);
        Event::assertDispatched(LeadQualified::class);
        Event::assertDispatched(StateChanged::class);
    }

    public function test_kanban_drag_drop_rejects_illegal_transition(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        // Won is terminal — can't move back.
        $lead = Lead::factory()->status(LeadStatus::Won)->create([
            'team_id' => $user->currentTeam->id,
        ]);

        $this->actingAs($user)
            ->patch("/leads/{$lead->id}/status", ['status' => LeadStatus::New->value])
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $this->assertSame(LeadStatus::Won, $lead->fresh()->status);
    }

    public function test_lead_delegator_fires_lead_assigned_event(): void
    {
        Event::fake([LeadAssigned::class]);

        $user = User::factory()->withPersonalTeam()->create();
        $lead = Lead::factory()->status(LeadStatus::Qualified)->create([
            'team_id' => $user->currentTeam->id,
        ]);

        app(LeadDelegator::class)->assign(
            lead: $lead,
            strategy: AssignmentStrategy::Manual,
            byUser: $user,
            toUserId: $user->id,
        );

        $this->assertSame(LeadStatus::Assigned, $lead->fresh()->status);
        // The big win — before the audit fix, this event never fired in
        // production because LeadDelegator did `$lead->status = ASSIGNED`
        // directly, bypassing the state machine entirely.
        Event::assertDispatched(LeadAssigned::class);
    }

    public function test_invalid_transition_via_json_returns_422(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $lead = Lead::factory()->status(LeadStatus::Won)->create([
            'team_id' => $user->currentTeam->id,
        ]);

        // PATCH via JSON to verify the exception handler renders 422 with
        // the structured payload (rather than 500 leaking the framework error).
        $this->actingAs($user)
            ->patchJson("/leads/{$lead->id}/status", ['status' => LeadStatus::New->value])
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'from', 'to', 'reason']);
    }

    public function test_plan_limit_exception_handler_renders_json_403(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        // Free plan caps at 1 agent — pre-create one.
        Agent::factory()->for($user->currentTeam)->create();

        $this->actingAs($user->fresh())
            ->postJson(route('agents.store'), ['name' => 'Second bot'])
            ->assertStatus(403)
            ->assertJsonStructure(['error', 'resource', 'limit', 'plan', 'plan_label']);
    }
}
