<?php

namespace Tests\Feature;

use App\Enums\AssignmentStrategy;
use App\Enums\LeadStatus;
use App\Events\LeadSaved;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Services\LeadDelegator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LeadDelegationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create an owner + N extra members on one team.
     *
     * @return array{0: User, 1: Team, 2: Collection<int, User>}
     */
    private function teamWithMembers(int $extra = 2): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        $members = collect();
        for ($i = 0; $i < $extra; $i++) {
            $u = User::factory()->create();
            $team->users()->attach($u, ['role' => 'editor']);
            $members->push($u);
        }

        return [$owner, $team, $members];
    }

    public function test_round_robin_spreads_leads_across_members(): void
    {
        [$owner, $team, $members] = $this->teamWithMembers(2);
        $delegator = app(LeadDelegator::class);

        $assignedTo = [];
        for ($i = 0; $i < 3; $i++) {
            $lead = Lead::factory()->status(LeadStatus::Qualified)->create(['team_id' => $team->id]);
            $user = $delegator->assign($lead, AssignmentStrategy::RoundRobin);
            $assignedTo[] = $user?->id;
        }

        // 3 leads across 3 members → each member exactly once.
        $this->assertCount(3, array_unique($assignedTo));
    }

    public function test_assignment_records_audit_and_sets_status(): void
    {
        [$owner, $team] = $this->teamWithMembers(1);
        $lead = Lead::factory()->status(LeadStatus::Qualified)->create(['team_id' => $team->id]);

        $user = app(LeadDelegator::class)->assign($lead, AssignmentStrategy::RoundRobin);

        $this->assertNotNull($user);
        $lead->refresh();
        $this->assertSame($user->id, $lead->assigned_to);
        $this->assertSame(LeadStatus::Assigned, $lead->status);
        $this->assertDatabaseHas('lead_assignments', [
            'lead_id' => $lead->id,
            'assigned_to' => $user->id,
            'strategy' => AssignmentStrategy::RoundRobin->value,
        ]);
    }

    public function test_least_loaded_picks_member_with_fewest_open_leads(): void
    {
        [$owner, $team, $members] = $this->teamWithMembers(1);
        $busy = $owner;
        $free = $members->first();

        // Give the owner 2 open leads.
        Lead::factory()->count(2)->create([
            'team_id' => $team->id,
            'assigned_to' => $busy->id,
            'status' => LeadStatus::Assigned,
        ]);

        $lead = Lead::factory()->status(LeadStatus::Qualified)->create(['team_id' => $team->id]);
        $user = app(LeadDelegator::class)->assign($lead, AssignmentStrategy::LeastLoaded);

        $this->assertSame($free->id, $user->id);
    }

    public function test_manual_assignment_via_endpoint_broadcasts(): void
    {
        Event::fake([LeadSaved::class]);
        [$owner, $team, $members] = $this->teamWithMembers(1);
        $target = $members->first();
        $lead = Lead::factory()->create(['team_id' => $team->id]);

        $this->actingAs($owner)
            ->post(route('leads.assign', $lead), [
                'strategy' => 'manual',
                'assigned_to' => $target->id,
            ])
            ->assertRedirect();

        $this->assertSame($target->id, $lead->fresh()->assigned_to);
        Event::assertDispatched(LeadSaved::class);
    }

    public function test_mine_filter_scopes_to_current_user(): void
    {
        [$owner, $team, $members] = $this->teamWithMembers(1);
        $other = $members->first();

        Lead::factory()->create(['team_id' => $team->id, 'assigned_to' => $owner->id]);
        Lead::factory()->create(['team_id' => $team->id, 'assigned_to' => $other->id]);

        $this->actingAs($owner)
            ->get(route('leads.index', ['mine' => 1]))
            ->assertInertia(fn ($page) => $page->has('leads', 1));
    }

    public function test_cannot_assign_lead_from_another_team(): void
    {
        [$owner] = $this->teamWithMembers(1);
        $foreign = Lead::factory()->create();

        $this->actingAs($owner)
            ->post(route('leads.assign', $foreign), ['strategy' => 'round_robin'])
            ->assertStatus(403);
    }
}
