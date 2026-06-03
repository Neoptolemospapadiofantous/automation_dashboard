<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Services\VoiceflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Locks in the post-audit fix: workspace surfaces (transcripts, KB CRUD,
 * KB query) authenticate with the agent's workspace key — NOT the DM key.
 *
 * Before this fix, forAgent() carried only voiceflow_api_key, and the
 * analytics/realtime/KB-query clients signed every workspace call with
 * the DM key, which 401s for most tenants.
 */
class VoiceflowWorkspaceKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_kb_query_uses_agent_workspace_key_not_dm_key(): void
    {
        Http::fake([
            'general-runtime.voiceflow.com/knowledge-base/query' => Http::response([
                'output' => 'answer',
                'chunks' => [],
            ], 200),
        ]);

        $agent = Agent::factory()->create([
            'voiceflow_api_key' => 'VF.DM.dm-key',
            'voiceflow_project_id' => 'proj-1',
            'voiceflow_workspace_api_key' => 'VF.WS.workspace-key',
        ]);

        VoiceflowService::forAgent($agent)->queryKnowledgeBase('What is X?');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/knowledge-base/query')
                && $request->header('authorization')[0] === 'VF.WS.workspace-key';
        });
    }

    public function test_transcripts_search_uses_workspace_key(): void
    {
        Http::fake([
            'analytics-api.voiceflow.com/v1/transcript/project/*' => Http::response(['transcripts' => []], 200),
        ]);

        $agent = Agent::factory()->create([
            'voiceflow_api_key' => 'VF.DM.dm-key',
            'voiceflow_project_id' => 'proj-1',
            'voiceflow_workspace_api_key' => 'VF.WS.workspace-key',
        ]);

        VoiceflowService::forAgent($agent)->searchTranscripts(10);

        Http::assertSent(fn ($r) => $r->header('authorization')[0] === 'VF.WS.workspace-key');
    }

    public function test_workspace_surfaces_fall_back_to_dm_key_when_workspace_key_absent(): void
    {
        // Tenants on the free Voiceflow tier sometimes only have a DM key.
        // We don't want to hard-block KB / transcripts for them — Voiceflow
        // *may* accept the DM key on these surfaces (account-dependent).
        // The service falls back rather than throwing so the call can be
        // attempted; if Voiceflow rejects it, the user gets a 401 with the
        // existing error-translation path.
        Http::fake([
            'general-runtime.voiceflow.com/knowledge-base/query' => Http::response([
                'output' => 'a', 'chunks' => [],
            ], 200),
        ]);

        $agent = Agent::factory()->create([
            'voiceflow_api_key' => 'VF.DM.only-dm',
            'voiceflow_project_id' => 'p',
            'voiceflow_workspace_api_key' => null,
        ]);

        VoiceflowService::forAgent($agent)->queryKnowledgeBase('x');

        Http::assertSent(fn ($r) => $r->header('authorization')[0] === 'VF.DM.only-dm');
    }

    public function test_dm_surfaces_always_use_the_dm_key_even_with_workspace_key_present(): void
    {
        // Confirm the inverse: state / interact / start-session always use
        // the DM key regardless of whether a workspace key is set. If this
        // ever flips, every conversation will 401.
        Http::fake([
            'general-runtime.voiceflow.com/state/user/*' => Http::response(['variables' => []], 200),
        ]);

        $agent = Agent::factory()->create([
            'voiceflow_api_key' => 'VF.DM.dm-key',
            'voiceflow_project_id' => 'p',
            'voiceflow_workspace_api_key' => 'VF.WS.ws-key',
        ]);

        VoiceflowService::forAgent($agent)->getVariables('u');

        Http::assertSent(fn ($r) => $r->header('authorization')[0] === 'VF.DM.dm-key');
    }
}
