<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Events\LeadDeleted;
use App\Events\LeadSaved;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LeadTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    public function test_guests_cannot_view_leads(): void
    {
        $this->get('/leads')->assertRedirect('/login');
    }

    public function test_board_only_shows_current_team_and_agent_leads(): void
    {
        // Phase G: scoping is by (team_id, current_agent_id), not team_id
        // alone. Set up an active agent + stamp the visible lead with it.
        $user = $this->user();
        $agent = \App\Models\Agent::factory()->for($user->currentTeam)->create();
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        $mine = Lead::factory()->create([
            'team_id' => $user->currentTeam->id,
            'agent_id' => $agent->id,
        ]);
        $other = Lead::factory()->create(); // different team

        $this->actingAs($user->fresh())->get('/leads')
            ->assertInertia(fn ($page) => $page
                ->component('Leads/Index')
                ->has('leads', 1)
                ->where('leads.0.id', $mine->id)
            );

        $this->assertDatabaseHas('leads', ['id' => $other->id]);
    }

    public function test_creating_a_lead_broadcasts(): void
    {
        Event::fake([LeadSaved::class]);
        $user = $this->user();

        $this->actingAs($user)
            ->post('/leads', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'])
            ->assertRedirect();

        $this->assertDatabaseHas('leads', [
            'name' => 'Ada Lovelace',
            'team_id' => $user->currentTeam->id,
        ]);
        Event::assertDispatched(LeadSaved::class);
    }

    public function test_status_can_be_changed_via_drag_and_drop_endpoint(): void
    {
        Event::fake([LeadSaved::class]);
        $user = $this->user();
        $lead = Lead::factory()->status(LeadStatus::New)->create(['team_id' => $user->currentTeam->id]);

        $this->actingAs($user)
            ->patch("/leads/{$lead->id}/status", ['status' => 'qualified'])
            ->assertRedirect();

        $this->assertEquals(LeadStatus::Qualified, $lead->fresh()->status);
        Event::assertDispatched(LeadSaved::class);
    }

    public function test_cannot_modify_a_lead_from_another_team(): void
    {
        $user = $this->user();
        $foreign = Lead::factory()->create(); // other team

        $this->actingAs($user)
            ->patch("/leads/{$foreign->id}/status", ['status' => 'won'])
            ->assertStatus(403);
    }

    public function test_deleting_a_lead_broadcasts(): void
    {
        Event::fake([LeadDeleted::class]);
        $user = $this->user();
        $lead = Lead::factory()->create(['team_id' => $user->currentTeam->id]);

        $this->actingAs($user)
            ->delete("/leads/{$lead->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
        Event::assertDispatched(LeadDeleted::class);
    }
}
