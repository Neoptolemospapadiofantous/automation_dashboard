<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Events\LeadSaved;
use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Coverage for every kanban category on the lead board:
 * New · Qualified · Assigned · Won · Lost, plus the
 * "Unassigned" (no rep) state the LeadCard renders.
 *
 * The board columns are driven entirely by LeadStatus, so this pins the
 * full category set, the per-category grouping the page consumes, and the
 * legal/illegal moves between every category.
 */
class LeadBoardCategoriesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A team owner whose current agent is set, plus that agent. Leads must
     * be stamped with this agent_id to appear (forAgent page scoping).
     *
     * @return array{0: User, 1: Agent}
     */
    private function userWithAgent(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        return [$user->fresh(), $agent];
    }

    private function leadInStatus(User $user, Agent $agent, LeadStatus $status, ?int $assignedTo = null): Lead
    {
        return Lead::factory()->status($status)->create([
            'team_id' => $user->currentTeam->id,
            'agent_id' => $agent->id,
            'assigned_to' => $assignedTo,
        ]);
    }

    public function test_board_exposes_every_status_category_in_order(): void
    {
        [$user] = $this->userWithAgent();

        $expected = [
            ['value' => 'new', 'label' => 'New', 'color' => 'sky'],
            ['value' => 'qualified', 'label' => 'Qualified', 'color' => 'violet'],
            ['value' => 'assigned', 'label' => 'Assigned', 'color' => 'blue'],
            ['value' => 'won', 'label' => 'Won', 'color' => 'green'],
            ['value' => 'lost', 'label' => 'Lost', 'color' => 'rose'],
        ];

        $this->actingAs($user)->get('/leads')
            ->assertInertia(fn ($page) => $page
                ->component('Leads/Index')
                ->where('statuses', $expected)
            );
    }

    public function test_every_category_groups_its_own_lead_on_the_board(): void
    {
        [$user, $agent] = $this->userWithAgent();

        $byStatus = [];
        foreach (LeadStatus::cases() as $status) {
            $byStatus[$status->value] = $this->leadInStatus($user, $agent, $status)->id;
        }

        $this->actingAs($user)->get('/leads')
            ->assertInertia(function ($page) use ($byStatus) {
                $page->component('Leads/Index')->has('leads', count($byStatus));

                $leads = collect($page->toArray()['props']['leads']);
                foreach ($byStatus as $value => $id) {
                    $match = $leads->firstWhere('id', $id);
                    $this->assertNotNull($match, "lead for category '{$value}' missing from board");
                    $this->assertSame($value, $match['status'], "lead {$id} not in '{$value}' column");
                }
            });
    }

    /**
     * @return array<string, array{LeadStatus, LeadStatus}>
     */
    public static function validTransitions(): array
    {
        return [
            'new → qualified' => [LeadStatus::New, LeadStatus::Qualified],
            'new → assigned' => [LeadStatus::New, LeadStatus::Assigned],
            'new → won' => [LeadStatus::New, LeadStatus::Won],
            'new → lost' => [LeadStatus::New, LeadStatus::Lost],
            'qualified → assigned' => [LeadStatus::Qualified, LeadStatus::Assigned],
            'qualified → won' => [LeadStatus::Qualified, LeadStatus::Won],
            'qualified → lost' => [LeadStatus::Qualified, LeadStatus::Lost],
            'assigned → won' => [LeadStatus::Assigned, LeadStatus::Won],
            'assigned → lost' => [LeadStatus::Assigned, LeadStatus::Lost],
            // One-step demotions (undo a mis-drag) and reopening a dead lead.
            'qualified → new (demotion)' => [LeadStatus::Qualified, LeadStatus::New],
            'assigned → qualified (demotion)' => [LeadStatus::Assigned, LeadStatus::Qualified],
            'lost → new (reopen)' => [LeadStatus::Lost, LeadStatus::New],
        ];
    }

    #[DataProvider('validTransitions')]
    public function test_valid_move_between_categories_is_accepted(LeadStatus $from, LeadStatus $to): void
    {
        Event::fake([LeadSaved::class]);
        [$user, $agent] = $this->userWithAgent();
        $lead = $this->leadInStatus($user, $agent, $from);

        $this->actingAs($user)
            ->patch("/leads/{$lead->id}/status", ['status' => $to->value])
            ->assertRedirect();

        $this->assertSame($to, $lead->fresh()->status);
        Event::assertDispatched(LeadSaved::class);
    }

    /**
     * Multi-step backward jumps and any move out of Won (terminal). The
     * endpoint surfaces InvalidTransition as 422 for the kanban UI.
     *
     * @return array<string, array{LeadStatus, LeadStatus}>
     */
    public static function invalidTransitions(): array
    {
        return [
            // Multi-step demotions stay blocked — undo one column at a time.
            'assigned → new (two-step demotion)' => [LeadStatus::Assigned, LeadStatus::New],
            'won → assigned (terminal)' => [LeadStatus::Won, LeadStatus::Assigned],
            'won → lost (terminal)' => [LeadStatus::Won, LeadStatus::Lost],
            'won → new (terminal)' => [LeadStatus::Won, LeadStatus::New],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function test_illegal_move_between_categories_is_rejected(LeadStatus $from, LeadStatus $to): void
    {
        [$user, $agent] = $this->userWithAgent();
        $lead = $this->leadInStatus($user, $agent, $from);

        $this->actingAs($user)
            ->patchJson("/leads/{$lead->id}/status", ['status' => $to->value])
            ->assertStatus(422);

        $this->assertSame($from, $lead->fresh()->status, 'lead status must be unchanged after a rejected move');
    }

    public function test_unassigned_lead_surfaces_with_no_assignee_then_assigns(): void
    {
        [$user, $agent] = $this->userWithAgent();
        $lead = $this->leadInStatus($user, $agent, LeadStatus::New, assignedTo: null);

        // On the board, an unassigned lead carries a null assignee — this is
        // what the LeadCard renders as "Unassigned".
        $this->actingAs($user)->get('/leads')
            ->assertInertia(fn ($page) => $page
                ->where('leads.0.id', $lead->id)
                ->where('leads.0.assigned_to', null)
                ->where('leads.0.assignee', null)
            );

        // Manually assigning a rep fills assigned_to and auto-advances the
        // lead into the Assigned category via the state machine.
        $this->actingAs($user)
            ->post("/leads/{$lead->id}/assign", [
                'strategy' => 'manual',
                'assigned_to' => $user->id,
            ])
            ->assertRedirect();

        $fresh = $lead->fresh();
        $this->assertSame($user->id, $fresh->assigned_to);
        $this->assertSame(LeadStatus::Assigned, $fresh->status);
    }
}
