<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Environments + Evaluations pages for legacy (Voiceflow) agents.
 *
 * Regression: Voiceflow's environments API returns Mongo-style `_id` /
 * `name` keys. The controller used to pass the raw body straight to the
 * Vue page, so env.alias AND env.id were undefined → Ziggy threw
 * "'alias' parameter is required for route 'agents.environments.export'"
 * during render and the whole list crashed.
 */
class VoiceflowEnvironmentsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_environments_index_normalizes_mongo_shaped_payloads(): void
    {
        Http::fake([
            'realtime-api.voiceflow.com/v1alpha1/project/*/environments' => Http::response([
                ['_id' => '6650aaa', 'name' => 'main', 'live' => true],          // Mongo shape
                ['id' => '6650bbb', 'alias' => 'staging', 'isLive' => false],    // documented shape
                ['someOtherKey' => 'junk'],                                      // unusable row
            ]),
            'realtime-api.voiceflow.com/*' => Http::response([]), // traffic split etc.
        ]);

        $user = $this->userWithVoiceflowAgent();

        $this->actingAs($user)
            ->get(route('agents.environments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Agents/Environments')
                ->where('configured', true)
                ->has('environments', 2) // junk row dropped, not crashed
                // Mongo shape → both ref fields populated, render-safe.
                ->where('environments.0.id', '6650aaa')
                ->where('environments.0.alias', 'main')
                ->where('environments.0.isLive', true)
                ->where('environments.1.id', '6650bbb')
                ->where('environments.1.alias', 'staging')
            );
    }

    public function test_environments_index_unconfigured_for_native_agent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'runtime_mode' => 'native',
            'voiceflow_api_key' => null,
            'voiceflow_project_id' => null,
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        // No crash, no platform-credential fallback — clean empty state.
        $this->actingAs($user->fresh())
            ->get(route('agents.environments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('configured', false)
                ->where('environments', [])
            );
    }

    public function test_evaluations_index_renders_for_voiceflow_agent(): void
    {
        Http::fake([
            'analytics-api.voiceflow.com/*' => Http::response([
                ['id' => 'eval-1', 'name' => 'Tone check', 'type' => 'binary'],
            ]),
        ]);

        $user = $this->userWithVoiceflowAgent();

        $this->actingAs($user)
            ->get(route('agents.evaluations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Agents/Evaluations')
                ->where('configured', true)
                ->has('evaluations', 1)
            );
    }

    public function test_evaluations_store_uses_the_agents_project_id_not_the_global_config(): void
    {
        config(['services.voiceflow.project_id' => 'platform-global-project']);
        Http::fake(['analytics-api.voiceflow.com/*' => Http::response(['id' => 'eval-new'])]);

        $user = $this->userWithVoiceflowAgent(projectId: 'agent-own-project-123');

        $this->actingAs($user)
            ->post(route('agents.evaluations.store'), [
                'name' => 'Qualification quality',
                'type' => 'boolean',
            ])
            ->assertRedirect();

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'transcript-evaluation')
                && $request['projectID'] === 'agent-own-project-123';
        });
    }

    private function userWithVoiceflowAgent(string $projectId = 'proj-legacy-1'): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'runtime_mode' => 'voiceflow',
            'status' => 'active',
            'voiceflow_api_key' => 'VF.DM.legacy-key',
            'voiceflow_project_id' => $projectId,
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        return $user->fresh();
    }
}
