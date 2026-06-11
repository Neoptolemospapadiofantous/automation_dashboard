<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Runtime\Models\KbDocument;
use App\Runtime\Models\RuntimeSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard's self-completing setup checklist — the in-app mirror of
 * docs/agent-lifecycle.md. Every step is derived from live data.
 */
class DashboardSetupChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_agent_shows_incomplete_checklist(): void
    {
        config(['runtime.llm.anthropic.api_key' => '', 'runtime.embeddings.openai_api_key' => '']);
        $user = $this->owner();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('setup.complete', false)
                ->where('setup.steps.0.key', 'engine')
                ->where('setup.steps.0.done', false)
                ->where('setup.steps.1.done', false) // knowledge
                ->where('setup.steps.5.done', false) // lead
            );
    }

    public function test_steps_flip_as_the_operator_works(): void
    {
        config(['runtime.llm.anthropic.api_key' => 'sk', 'runtime.embeddings.openai_api_key' => 'sk']);
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;

        KbDocument::create([
            'agent_id' => $agent->id, 'title' => 'FAQ', 'source' => 'text',
            'raw_content' => 'x', 'metadata' => [], 'chunk_count' => 0,
        ]);
        AgentConfigVersion::create([
            'agent_id' => $agent->id, 'version' => 1, 'status' => 'published',
            'config' => ['instructions' => 'x', 'greeting' => ''], 'published_at' => now(),
        ]);
        Conversation::factory()->create(['team_id' => $user->currentTeam->id, 'agent_id' => $agent->id]);
        RuntimeSession::create([
            'agent_id' => $agent->id, 'visitor_id' => 'embed-abc123', 'flow_state' => 'discovery',
            'variables' => [], 'history' => [],
        ]);
        Lead::factory()->create(['team_id' => $user->currentTeam->id, 'agent_id' => $agent->id]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('setup.complete', true));
    }

    public function test_dashboard_chat_session_does_not_count_as_install(): void
    {
        config(['runtime.llm.anthropic.api_key' => 'sk', 'runtime.embeddings.openai_api_key' => 'sk']);
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;

        // Dashboard chat sessions use web- prefixed visitor ids — they must
        // NOT tick the "widget installed" step.
        RuntimeSession::create([
            'agent_id' => $agent->id, 'visitor_id' => 'web-not-embed', 'flow_state' => 'discovery',
            'variables' => [], 'history' => [],
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('setup.steps.4.key', 'install')
                ->where('setup.steps.4.done', false)
            );
    }

    private function owner(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        return $user->fresh();
    }
}
