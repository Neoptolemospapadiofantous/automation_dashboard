<?php

namespace Tests\Security;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Runtime\Models\KbDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Category F — cross-tenant isolation.
 *
 * User A (team A) must never read team B's agents, leads, conversations
 * or knowledge documents by URL guessing. Public embed endpoints must
 * refuse non-active agents.
 */
class CrossTenantTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;

    private User $userB;

    private Agent $agentA;

    private Agent $agentB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->userA, $this->agentA] = $this->ownerWithAgent();
        [$this->userB, $this->agentB] = $this->ownerWithAgent();
    }

    public function test_cannot_view_another_teams_agent(): void
    {
        $this->actingAs($this->userA)
            ->get(route('agents.show', $this->agentB->slug))
            ->assertForbidden();
    }

    public function test_cannot_view_another_teams_lead(): void
    {
        $lead = Lead::factory()->create([
            'team_id' => $this->userB->currentTeam->id,
            'agent_id' => $this->agentB->id,
        ]);

        $this->actingAs($this->userA)
            ->get(route('leads.show', $lead))
            ->assertForbidden();
    }

    public function test_cannot_view_another_teams_conversation(): void
    {
        $conversation = Conversation::factory()->create([
            'team_id' => $this->userB->currentTeam->id,
            'agent_id' => $this->agentB->id,
        ]);

        $status = $this->actingAs($this->userA)
            ->get(route('conversations.show', $conversation))
            ->status();

        // 403 (team mismatch) is what ships today; 404 (existence hidden)
        // would be acceptable too. Anything else is a leak.
        $this->assertContains($status, [403, 404]);
    }

    public function test_cannot_read_another_agents_knowledge_document(): void
    {
        $doc = KbDocument::create([
            'agent_id' => $this->agentB->id,
            'title' => 'Team B secrets',
            'source' => 'text',
            'raw_content' => 'internal pricing floor is $12',
            'chunk_count' => 0,
        ]);

        // User A's current agent is agent A — the lookup is agent-scoped,
        // so team B's document must read as nonexistent.
        $this->actingAs($this->userA)
            ->getJson(route('knowledge.show', $doc->id))
            ->assertNotFound();
    }

    public function test_inactive_agent_slug_404s_on_embed_launch(): void
    {
        $inactive = Agent::factory()->create(['status' => Agent::STATUS_DISABLED]);

        $this->postJson("/embed/{$inactive->slug}/launch")->assertNotFound();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** @return array{0: User, 1: Agent} */
    private function ownerWithAgent(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        return [$user->fresh(), $agent];
    }
}
