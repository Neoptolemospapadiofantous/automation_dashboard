<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase G — page scoping by current_agent_id.
 *
 * Locks in: switching the team's current agent swaps the data shown on
 * Leads, Conversations, Dashboard, and Search. Cross-agent leakage is
 * the canary — if any test in here ever passes when it shouldn't, the
 * scope has been bypassed somewhere.
 */
class AgentScopingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a user whose team has two agents, with `currentAgent` set,
     * and a small fixture of rows on each agent so cross-agent leakage
     * is detectable.
     *
     * @return array{user: User, agentA: Agent, agentB: Agent}
     */
    private function twoAgentSetup(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $agentA = Agent::factory()->for($team)->create(['name' => 'Agent A']);
        $agentB = Agent::factory()->for($team)->create(['name' => 'Agent B']);

        $team->forceFill(['current_agent_id' => $agentA->id])->save();

        // 2 leads + 1 conversation under A, 1 of each under B. Pin the
        // status to New so the dashboard counter test below can assert
        // exact totals — LeadFactory's default status is randomised.
        Lead::factory()->count(2)->status(LeadStatus::New)
            ->create(['team_id' => $team->id, 'agent_id' => $agentA->id]);
        Lead::factory()->status(LeadStatus::New)
            ->create(['team_id' => $team->id, 'agent_id' => $agentB->id]);

        Conversation::factory()->create(['team_id' => $team->id, 'agent_id' => $agentA->id]);
        Conversation::factory()->create(['team_id' => $team->id, 'agent_id' => $agentB->id]);

        return ['user' => $user->fresh(), 'agentA' => $agentA, 'agentB' => $agentB];
    }

    public function test_leads_index_shows_only_current_agent(): void
    {
        ['user' => $user, 'agentB' => $agentB] = $this->twoAgentSetup();

        // Currently on agent A → see 2 leads.
        $this->actingAs($user)->get(route('leads.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('leads', 2));

        // Switch to agent B → see 1 lead.
        $user->currentTeam->forceFill(['current_agent_id' => $agentB->id])->save();

        $this->actingAs($user->fresh())->get(route('leads.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('leads', 1));
    }

    public function test_conversations_index_shows_only_current_agent(): void
    {
        ['user' => $user, 'agentB' => $agentB] = $this->twoAgentSetup();

        $this->actingAs($user)->get(route('conversations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('conversations.data', 1));

        $user->currentTeam->forceFill(['current_agent_id' => $agentB->id])->save();

        $this->actingAs($user->fresh())->get(route('conversations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('conversations.data', 1));
    }

    public function test_conversation_show_404s_for_other_agent(): void
    {
        ['user' => $user, 'agentB' => $agentB] = $this->twoAgentSetup();

        // A conversation belonging to agent B, while the user is on agent A.
        $otherConvo = Conversation::factory()->create([
            'team_id' => $user->currentTeam->id,
            'agent_id' => $agentB->id,
        ]);

        $this->actingAs($user)->get(route('conversations.show', $otherConvo))
            ->assertStatus(404);

        // Switching makes it accessible.
        $user->currentTeam->forceFill(['current_agent_id' => $agentB->id])->save();
        $this->actingAs($user->fresh())->get(route('conversations.show', $otherConvo))
            ->assertOk();
    }

    public function test_dashboard_counters_swap_with_agent(): void
    {
        ['user' => $user, 'agentA' => $agentA, 'agentB' => $agentB] = $this->twoAgentSetup();

        // Add some won leads on A so the counter has something to swap.
        Lead::factory()->count(3)->create([
            'team_id' => $user->currentTeam->id,
            'agent_id' => $agentA->id,
            'status' => LeadStatus::Won,
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total_leads', 5) // 2 + 3
                ->where('stats.won', 3)
            );

        // Switch to B — only sees its 1 lead, 0 won.
        $user->currentTeam->forceFill(['current_agent_id' => $agentB->id])->save();

        $this->actingAs($user->fresh())->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total_leads', 1)
                ->where('stats.won', 0)
            );
    }

    public function test_search_returns_only_current_agent_messages(): void
    {
        ['user' => $user, 'agentA' => $agentA, 'agentB' => $agentB] = $this->twoAgentSetup();

        $convoA = Conversation::factory()->create(['team_id' => $user->currentTeam->id, 'agent_id' => $agentA->id]);
        $convoB = Conversation::factory()->create(['team_id' => $user->currentTeam->id, 'agent_id' => $agentB->id]);

        Message::factory()->for($convoA, 'conversation')->create(['text' => 'hello unique-token-a']);
        Message::factory()->for($convoB, 'conversation')->create(['text' => 'hello unique-token-b']);

        // Currently on A — A's match shows, B's doesn't.
        $this->actingAs($user)->get(route('conversations.search', ['q' => 'unique-token']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('results', 1));
    }

    public function test_no_current_agent_yields_empty_results(): void
    {
        // Edge: user reaches a list page somehow without a current agent
        // (test middleware bypassed). forAgent(null) should yield no rows
        // — explicit safety so cross-tenant data can't leak.
        $user = User::factory()->withPersonalTeam()->create();
        Lead::factory()->count(3)->create(['team_id' => $user->currentTeam->id]);
        // No current_agent_id set; no agent created.

        $this->actingAs($user)->get(route('leads.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('leads', 0));
    }
}
