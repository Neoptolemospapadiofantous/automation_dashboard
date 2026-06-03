<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Services\VoiceflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Locks in the post-audit fix: getVariables() now uses the canonical
 * GET /state/user/{userID} endpoint with a projectID header, NOT the
 * fabricated /v4/project/.../user/.../state path the original code had.
 *
 * Source: docs/voiceflow/conversations/get-conversation-state.md
 */
class VoiceflowStateEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_variables_hits_canonical_state_endpoint(): void
    {
        // Only the canonical path responds; anything else (including the
        // old /v4/.../state path) gets 404 so the test catches regressions.
        Http::fake([
            'general-runtime.voiceflow.com/state/user/*' => Http::response([
                'turn' => [],
                'stack' => [],
                'variables' => ['name' => 'Ada', 'email' => 'ada@example.com'],
                'storage' => [],
            ], 200),
            'general-runtime.voiceflow.com/v4/*' => Http::response(['error' => 'fabricated endpoint hit'], 404),
        ]);

        $agent = Agent::factory()->create([
            'voiceflow_api_key' => 'VF.DM.test',
            'voiceflow_project_id' => 'proj-abc',
        ]);

        $vars = VoiceflowService::forAgent($agent)->getVariables('user-xyz');

        $this->assertSame(['name' => 'Ada', 'email' => 'ada@example.com'], $vars);

        // Explicitly assert the request shape — projectID header must be
        // present per the doc; absence is a silent auth bug.
        Http::assertSent(function ($request) {
            return $request->url() === 'https://general-runtime.voiceflow.com/state/user/user-xyz'
                && $request->method() === 'GET'
                && $request->header('projectID')[0] === 'proj-abc'
                && $request->header('authorization')[0] === 'VF.DM.test';
        });
    }

    public function test_get_variables_returns_empty_array_on_state_without_variables(): void
    {
        Http::fake([
            'general-runtime.voiceflow.com/state/user/*' => Http::response([
                'turn' => [],
                'stack' => [],
                // No variables key at all — older state responses sometimes
                // omit it before the agent has run any Set blocks.
                'storage' => [],
            ], 200),
        ]);

        $agent = Agent::factory()->create([
            'voiceflow_api_key' => 'VF.DM.test',
            'voiceflow_project_id' => 'p',
        ]);

        $vars = VoiceflowService::forAgent($agent)->getVariables('u');

        // Earlier code did $vars = $json['variables'] ?? $json — falling
        // back to the whole payload would have leaked turn/stack/storage
        // into the captured-fields pipeline. Confirm we return [] instead.
        $this->assertSame([], $vars);
    }
}
