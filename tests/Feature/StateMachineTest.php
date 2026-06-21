<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Events\Domain\AgentActivated;
use App\Events\Domain\LeadQualified;
use App\Events\Domain\LeadStatusChanged;
use App\Events\Domain\StateChanged;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class StateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_can_transition_forward(): void
    {
        $lead = Lead::factory()->status(LeadStatus::New)->create();

        $lead->transitionTo(LeadStatus::Qualified);

        $this->assertSame(LeadStatus::Qualified, $lead->fresh()->status);
    }

    public function test_lead_can_demote_one_step(): void
    {
        // A mis-dragged card can be undone one column at a time.
        $lead = Lead::factory()->status(LeadStatus::Qualified)->create();

        $lead->transitionTo(LeadStatus::New);

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }

    public function test_lead_cannot_demote_more_than_one_step(): void
    {
        // Assigned → New skips a column — blocked; undo one step at a time.
        $lead = Lead::factory()->status(LeadStatus::Assigned)->create();

        $this->expectException(InvalidArgumentException::class);
        $lead->transitionTo(LeadStatus::New);
    }

    public function test_lost_lead_can_be_reopened(): void
    {
        $lead = Lead::factory()->status(LeadStatus::Lost)->create();

        $lead->transitionTo(LeadStatus::New);

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }

    public function test_won_lead_is_terminal(): void
    {
        $lead = Lead::factory()->status(LeadStatus::Won)->create();

        $this->expectException(InvalidArgumentException::class);
        $lead->transitionTo(LeadStatus::New);
    }

    public function test_lead_terminal_states_have_no_outgoing_transitions(): void
    {
        $won = Lead::factory()->status(LeadStatus::Won)->create();

        $this->expectException(InvalidArgumentException::class);
        $won->transitionTo(LeadStatus::Assigned);
    }

    public function test_lead_transition_fires_specific_event(): void
    {
        Event::fake([LeadQualified::class, LeadStatusChanged::class, StateChanged::class]);

        $lead = Lead::factory()->status(LeadStatus::New)->create();
        $lead->transitionTo(LeadStatus::Qualified);

        Event::assertDispatched(LeadQualified::class);
        // The generic StateChanged also fires for cross-cutting listeners.
        Event::assertDispatched(StateChanged::class);
    }

    public function test_agent_draft_to_active_is_blocked_when_unhealthy(): void
    {
        // Configured but last_health_ok = false → guard rejects.
        $agent = Agent::factory()->create([
            'status' => Agent::STATUS_DRAFT,
            'last_health_ok' => false,
        ]);

        $this->assertFalse($agent->canTransitionTo(Agent::STATUS_ACTIVE));

        $this->expectException(InvalidArgumentException::class);
        $agent->transitionTo(Agent::STATUS_ACTIVE);
    }

    public function test_agent_draft_to_active_succeeds_with_creds_and_health(): void
    {
        Event::fake([AgentActivated::class]);

        $agent = Agent::factory()->create([
            'status' => Agent::STATUS_DRAFT,
            'last_health_ok' => true,
        ]);

        $agent->transitionTo(Agent::STATUS_ACTIVE);

        $this->assertSame(Agent::STATUS_ACTIVE, $agent->fresh()->status);
        Event::assertDispatched(AgentActivated::class);
    }

    public function test_agent_disabled_to_active_re_enables(): void
    {
        // Native agents carry no credentials — re-enabling a paused agent
        // must always work (the old credential guard locked agents out).
        $agent = Agent::factory()->create(['status' => Agent::STATUS_DISABLED]);

        $agent->transitionTo(Agent::STATUS_ACTIVE);

        $this->assertSame(Agent::STATUS_ACTIVE, $agent->fresh()->status);
    }

    public function test_conversation_can_only_end(): void
    {
        $team = Team::factory()->create();
        $convo = Conversation::factory()->for($team)->create(['status' => 'active']);

        $convo->transitionTo('ended');
        $this->assertSame('ended', $convo->fresh()->status);

        $this->expectException(InvalidArgumentException::class);
        $convo->transitionTo('active'); // terminal — no going back
    }
}
