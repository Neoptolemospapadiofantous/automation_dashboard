<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\Models\KbGap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Knowledge page's gap work list: unanswered questions surface ranked
 * by demand, and resolving one deletes it (agent-scoped, capability-gated).
 * Recording itself is covered in Runtime\GroundedAnswersTest.
 */
class KnowledgeGapsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['runtime.embeddings.openai_api_key' => 'sk-test']);
    }

    public function test_gaps_render_on_knowledge_page_ranked_by_demand(): void
    {
        $user = $this->ownerWithCurrentAgent($agent);

        KbGap::record($agent->id, 'Do you support SAML SSO?', 0.20);
        KbGap::record($agent->id, 'Can I export my data?', 0.30);
        KbGap::record($agent->id, 'Can I export my data?', 0.35);

        $this->actingAs($user)
            ->get(route('knowledge.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('gaps', 2)
                ->where('gaps.0.question', 'Can I export my data?')
                ->where('gaps.0.asked_count', 2)
                ->where('gaps.1.question', 'Do you support SAML SSO?')
            );
    }

    public function test_resolving_a_gap_deletes_it(): void
    {
        $user = $this->ownerWithCurrentAgent($agent);
        KbGap::record($agent->id, 'Do you support SAML SSO?', 0.20);
        $gap = KbGap::query()->firstOrFail();

        $this->actingAs($user)
            ->delete(route('knowledge.gaps.resolve', $gap->id))
            ->assertRedirect();

        $this->assertSame(0, KbGap::query()->count());
    }

    public function test_cannot_resolve_another_teams_gap(): void
    {
        $this->ownerWithCurrentAgent($foreignAgent);
        KbGap::record($foreignAgent->id, 'Their question', 0.10);
        $gap = KbGap::query()->firstOrFail();

        $me = $this->ownerWithCurrentAgent($myAgent);

        $this->actingAs($me)
            ->delete(route('knowledge.gaps.resolve', $gap->id))
            ->assertForbidden();

        $this->assertSame(1, KbGap::query()->count());
    }

    private function ownerWithCurrentAgent(?Agent &$agent): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['runtime_mode' => 'native']);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        return $user;
    }
}
