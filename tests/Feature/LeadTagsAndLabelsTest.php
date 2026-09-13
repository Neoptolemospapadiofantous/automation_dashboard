<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Support\Tags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadTagsAndLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tags_are_normalised_deduped_and_capped(): void
    {
        $this->assertSame(['hot', 'follow up', 'vip'], Tags::normalize([' Hot ', 'hot', 'Follow   Up', '', 'VIP']));
        $this->assertSame(['a', 'b'], Tags::normalize('a, b ;a'));
        $this->assertCount(Tags::MAX_TAGS, Tags::normalize(range(1, 40)));
        $this->assertSame([], Tags::normalize(str_repeat('x', 41)));
    }

    public function test_lead_tags_round_trip_and_filter_the_board(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        $hot = Lead::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id, 'status' => 'new']);
        $cold = Lead::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id, 'status' => 'new']);

        $this->actingAs($user)
            ->patchJson(route('leads.tags', $hot->id), ['tags' => ['Hot', 'hot ', 'VIP']])
            ->assertOk()
            ->assertJson(['tags' => ['hot', 'vip']]);

        $this->assertSame(['hot', 'vip'], $hot->fresh()->tags);

        $this->actingAs($user)
            ->get(route('leads.index', ['tag' => 'hot']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.tag', 'hot')
                ->where('tags', ['hot', 'vip'])
                ->has('leads', 1)
                ->where('leads.0.id', $hot->id)
            );

        $this->assertNull($cold->fresh()->tags);
    }

    public function test_tags_are_team_scoped(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $lead = Lead::factory()->create(['team_id' => $other->currentTeam->id]);

        $this->actingAs($user)
            ->patchJson(route('leads.tags', $lead->id), ['tags' => ['x']])
            ->assertForbidden();
    }

    public function test_conversation_labels_round_trip_and_filter_the_list(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        $billing = Conversation::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id]);
        Conversation::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id]);

        $this->actingAs($user)
            ->patchJson(route('conversations.labels', $billing->id), ['labels' => ['Billing', 'billing']])
            ->assertOk()
            ->assertJson(['labels' => ['billing']]);

        $this->actingAs($user)
            ->get(route('conversations.index', ['label' => 'billing']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.label', 'billing')
                ->where('label_options', ['billing'])
                ->has('conversations.data', 1)
                ->where('conversations.data.0.id', $billing->id)
            );
    }
}
