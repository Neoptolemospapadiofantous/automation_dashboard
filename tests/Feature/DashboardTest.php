<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_reports_agent_scoped_stats(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        // Phase G: the dashboard scopes by current_agent_id, not just team_id.
        // Set up the current agent + tag the team's leads with it.
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        Lead::factory()->count(2)->create([
            'team_id' => $team->id, 'agent_id' => $agent->id, 'status' => LeadStatus::Won,
        ]);
        Lead::factory()->create([
            'team_id' => $team->id, 'agent_id' => $agent->id, 'status' => LeadStatus::Lost,
        ]);
        Lead::factory()->create([
            'team_id' => $team->id, 'agent_id' => $agent->id, 'status' => LeadStatus::Qualified,
        ]);

        // A lead on a DIFFERENT agent in the same team must not leak in.
        $otherAgent = Agent::factory()->for($team)->create();
        Lead::factory()->create([
            'team_id' => $team->id, 'agent_id' => $otherAgent->id, 'status' => LeadStatus::Won,
        ]);

        // A lead on a different team must not leak in.
        Lead::factory()->create(['status' => LeadStatus::Won]);

        $this->actingAs($user->fresh())->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('stats.total_leads', 4)
                ->where('stats.won', 2)
                ->where('stats.lost', 1)
                ->where('stats.qualified', 1)
                // 2 won of 3 decided = 66.7%
                ->where('stats.conversion_rate', 66.7)
                ->has('funnel', 5)
                ->has('rep_load')
                ->has('series.total_leads.points', 7)
                ->has('series.won.delta')
                ->has('queue')
                ->has('activity')
            );
    }

    public function test_dashboard_requires_auth(): void
    {
        $this->get(route('dashboard'))->assertRedirect('/login');
    }
}
