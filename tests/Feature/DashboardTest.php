<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_reports_team_scoped_stats(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        Lead::factory()->count(2)->create(['team_id' => $team->id, 'status' => LeadStatus::Won]);
        Lead::factory()->create(['team_id' => $team->id, 'status' => LeadStatus::Lost]);
        Lead::factory()->create(['team_id' => $team->id, 'status' => LeadStatus::Qualified]);
        // Another team's lead must not leak in.
        Lead::factory()->create(['status' => LeadStatus::Won]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('stats.total_leads', 4)
                ->where('stats.won', 2)
                ->where('stats.lost', 1)
                ->where('stats.qualified', 1)
                // 2 won of 3 decided = 66.7%
                ->where('stats.conversion_rate', 66.7)
                ->has('funnel', 6)
                ->has('rep_load')
            );
    }

    public function test_dashboard_requires_auth(): void
    {
        $this->get(route('dashboard'))->assertRedirect('/login');
    }
}
