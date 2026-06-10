<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadsSearchAndShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_search_filters_by_name_email_company_phone(): void
    {
        [$user, $agent] = $this->scaffold();

        $a = Lead::factory()->for($user->currentTeam)->create(['agent_id' => $agent->id, 'name' => 'Alice Anderson']);
        $b = Lead::factory()->for($user->currentTeam)->create(['agent_id' => $agent->id, 'name' => 'Bob Builder', 'email' => 'bob@buyer.co']);
        $c = Lead::factory()->for($user->currentTeam)->create(['agent_id' => $agent->id, 'name' => 'Carol Carpenter', 'company' => 'Acme Inc']);

        $this->actingAs($user)
            ->get(route('leads.index', ['q' => 'alice']))
            ->assertInertia(fn ($p) => $p->has('leads', 1)->where('leads.0.id', $a->id));

        $this->actingAs($user)
            ->get(route('leads.index', ['q' => 'buyer.co']))
            ->assertInertia(fn ($p) => $p->has('leads', 1)->where('leads.0.id', $b->id));

        $this->actingAs($user)
            ->get(route('leads.index', ['q' => 'Acme']))
            ->assertInertia(fn ($p) => $p->has('leads', 1)->where('leads.0.id', $c->id));
    }

    public function test_status_filter(): void
    {
        [$user, $agent] = $this->scaffold();

        $newLead = Lead::factory()->for($user->currentTeam)->create(['agent_id' => $agent->id, 'status' => LeadStatus::New->value]);
        Lead::factory()->for($user->currentTeam)->create(['agent_id' => $agent->id, 'status' => LeadStatus::Qualified->value]);

        $this->actingAs($user)
            ->get(route('leads.index', ['status' => LeadStatus::New->value]))
            ->assertInertia(fn ($p) => $p->has('leads', 1)->where('leads.0.id', $newLead->id));
    }

    public function test_min_score_filter_clamps_and_filters(): void
    {
        [$user, $agent] = $this->scaffold();
        Lead::factory()->for($user->currentTeam)->create(['agent_id' => $agent->id, 'score' => 30]);
        Lead::factory()->for($user->currentTeam)->create(['agent_id' => $agent->id, 'score' => 75]);
        Lead::factory()->for($user->currentTeam)->create(['agent_id' => $agent->id, 'score' => 92]);

        $this->actingAs($user)
            ->get(route('leads.index', ['min_score' => 70]))
            ->assertInertia(fn ($p) => $p->has('leads', 2));

        // Out-of-bounds clamps to [0, 100] — 999 → 100, only the 92 matches.
        $this->actingAs($user)
            ->get(route('leads.index', ['min_score' => 999]))
            ->assertInertia(fn ($p) => $p->has('leads', 0));
    }

    public function test_since_filter_returns_only_recent_leads(): void
    {
        [$user, $agent] = $this->scaffold();

        $old = Lead::factory()->for($user->currentTeam)->create(['agent_id' => $agent->id]);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $recent = Lead::factory()->for($user->currentTeam)->create(['agent_id' => $agent->id]);
        $recent->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->actingAs($user)
            ->get(route('leads.index', ['since' => '7d']))
            ->assertInertia(fn ($p) => $p->has('leads', 1)->where('leads.0.id', $recent->id));
    }

    public function test_sources_dropdown_exposes_distinct_sources(): void
    {
        [$user, $agent] = $this->scaffold();
        Lead::factory()->for($user->currentTeam)->create(['agent_id' => $agent->id, 'source' => 'website']);
        Lead::factory()->for($user->currentTeam)->create(['agent_id' => $agent->id, 'source' => 'website']);
        Lead::factory()->for($user->currentTeam)->create(['agent_id' => $agent->id, 'source' => 'embed']);

        $this->actingAs($user)
            ->get(route('leads.index'))
            ->assertInertia(fn ($p) => $p->has('sources', 2)
                ->where('sources.0', 'embed')
                ->where('sources.1', 'website'));
    }

    public function test_show_page_renders_with_full_context(): void
    {
        [$user, $agent] = $this->scaffold();
        $lead = Lead::factory()->for($user->currentTeam)->create([
            'agent_id' => $agent->id,
            'name' => 'Detail Person',
            'notes' => 'Hot lead — followup Friday',
        ]);

        $this->actingAs($user)
            ->get(route('leads.show', $lead->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Leads/Show')
                ->where('lead.id', $lead->id)
                ->where('lead.name', 'Detail Person')
                ->where('lead.notes', 'Hot lead — followup Friday')
                ->has('conversations')
                ->has('statuses')
                ->has('members')
            );
    }

    public function test_show_page_blocks_foreign_lead(): void
    {
        [$me] = $this->scaffold();
        [$other, $otherAgent] = $this->scaffold();

        $foreign = Lead::factory()->for($other->currentTeam)->create(['agent_id' => $otherAgent->id]);

        $this->actingAs($me)
            ->get(route('leads.show', $foreign->id))
            ->assertForbidden();
    }

    /** @return array{0: User, 1: Agent} */
    private function scaffold(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        return [$user->fresh(), $agent];
    }
}
