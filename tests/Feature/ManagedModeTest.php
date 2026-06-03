<?php

namespace Tests\Feature;

use App\Actions\Agents\CreateAgent;
use App\Http\Middleware\RequireAgent;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase J — managed mode: we own the Voiceflow workspace + master project,
 * CreateAgent clones the template environment on signup, users never paste
 * Voiceflow credentials.
 */
class ManagedModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.voiceflow.api_key', 'VF.DM.master-key');
        config()->set('services.voiceflow.workspace_api_key', 'VF.WS.master-workspace-key');
        config()->set('services.voiceflow.managed.enabled', true);
        config()->set('services.voiceflow.managed.master_project_id', 'master-proj-abc');
        config()->set('services.voiceflow.managed.template_environment_id', 'tmpl-env-001');
    }

    public function test_create_agent_clones_environment_and_marks_active(): void
    {
        // Voiceflow returns a freshly minted env id from the clone call.
        Http::fake([
            'realtime-api.voiceflow.com/v1alpha1/project/master-proj-abc/environment' => Http::response([
                '_id' => 'cloned-env-xyz',
                'name' => 'Acme — Default agent',
            ], 201),
        ]);

        $user = User::factory()->withPersonalTeam()->create();

        $agent = (new CreateAgent())->execute($user->currentTeam, 'Default agent');

        $this->assertSame(Agent::MODE_MANAGED, $agent->mode);
        $this->assertSame(Agent::STATUS_ACTIVE, $agent->status);
        $this->assertSame('cloned-env-xyz', $agent->voiceflow_environment);
        $this->assertNull($agent->voiceflow_api_key, 'managed agent stores no per-row api key');
        $this->assertNull($agent->voiceflow_project_id, 'managed agent stores no per-row project_id');
        $this->assertTrue((bool) $agent->last_health_ok, 'clone success implies the env is healthy');

        // Sanity check the wire shape — the clone POST went to the right URL
        // with the workspace key and the template env id in the body.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1alpha1/project/master-proj-abc/environment')
                && $request->header('authorization')[0] === 'VF.WS.master-workspace-key'
                && $request['cloneFromEnvironmentID'] === 'tmpl-env-001';
        });
    }

    public function test_managed_create_propagates_voiceflow_failure(): void
    {
        // Voiceflow rejects the clone — make sure we don't leave a stranded
        // local agent row.
        Http::fake([
            'realtime-api.voiceflow.com/v1alpha1/project/*/environment' => Http::response(['error' => 'forbidden'], 403),
        ]);

        $user = User::factory()->withPersonalTeam()->create();
        $teamId = $user->currentTeam->id;

        try {
            (new CreateAgent())->execute($user->currentTeam, 'Should fail');
            $this->fail('Expected the clone failure to bubble up.');
        } catch (\Throwable $e) {
            // Expected. Confirm no rogue agent row was created.
            $this->assertDatabaseMissing('agents', ['team_id' => $teamId]);
        }
    }

    public function test_managed_isconfigured_requires_env_config(): void
    {
        // Even with a non-null voiceflow_environment, the managed agent
        // isn't configured if .env doesn't have the master keys set.
        config()->set('services.voiceflow.api_key', null);

        $agent = Agent::factory()->for(User::factory()->withPersonalTeam()->create()->currentTeam)->create([
            'mode' => Agent::MODE_MANAGED,
            'voiceflow_api_key' => null,
            'voiceflow_project_id' => null,
            'voiceflow_environment' => 'env-x',
        ]);

        $this->assertFalse($agent->isConfigured());

        config()->set('services.voiceflow.api_key', 'VF.DM.x');
        $this->assertTrue($agent->fresh()->isConfigured());
    }

    public function test_byok_agents_unaffected_by_managed_mode_flag(): void
    {
        // The mode flag controls how NEW agents get provisioned, not how
        // existing BYOK agents behave. An existing BYOK agent still
        // authenticates with its own row-stored credentials.
        $user = User::factory()->withPersonalTeam()->create();
        $byok = Agent::factory()->for($user->currentTeam)->create([
            'mode' => Agent::MODE_BYOK,
            'voiceflow_api_key' => 'VF.DM.byok-tenant',
            'voiceflow_project_id' => 'byok-proj',
        ]);

        $service = \App\Services\VoiceflowService::forAgent($byok);
        $ref = new \ReflectionClass($service);
        $apiKey = $ref->getProperty('apiKey');
        $apiKey->setAccessible(true);

        $this->assertSame('VF.DM.byok-tenant', $apiKey->getValue($service),
            'BYOK agent must keep using its own key even when managed mode is on globally');
    }

    public function test_settings_page_hides_credentials_for_managed_agent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'mode' => Agent::MODE_MANAGED,
            'voiceflow_api_key' => null,
            'voiceflow_project_id' => null,
            'voiceflow_environment' => 'cloned-env-xyz',
            'voiceflow_workspace_api_key' => null,
        ]);

        $this->actingAs($user->fresh())->get(route('agents.show', $agent))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Agents/Show')
                ->where('agent.mode', Agent::MODE_MANAGED)
                // Webhook URL is null for managed agents — we configure the
                // Voiceflow Custom Action on our master template, not theirs.
                ->where('webhook_url', null)
                // None of the credential-ish keys should appear in the
                // settings projection for managed agents — explicitly
                // missing, not present-and-null. ->missing() catches
                // accidental "leak by re-adding to the projection".
                ->missing('agent.voiceflow_project_id')
                ->missing('agent.voiceflow_environment')
                ->missing('agent.webhook_secret')
                ->missing('agent.has_api_key')
                ->missing('agent.has_workspace_api_key')
            );
    }

    public function test_managed_update_silently_drops_credential_writes(): void
    {
        // Defence-in-depth: a clever curl trying to write credentials to a
        // managed agent gets validation success + no-op behaviour, not a
        // route into stamping per-row creds on something that's supposed
        // to be controlled by env.
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'mode' => Agent::MODE_MANAGED,
            'voiceflow_api_key' => null,
            'voiceflow_project_id' => null,
            'voiceflow_environment' => 'cloned-env-xyz',
        ]);

        $this->actingAs($user->fresh())
            ->put(route('agents.update', $agent), [
                'name' => 'Renamed',
                'voiceflow_api_key' => 'VF.DM.attempted-injection.x',
                'voiceflow_project_id' => '111111111111111111111111',
            ])
            ->assertRedirect();

        $fresh = $agent->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertNull($fresh->voiceflow_api_key, 'credential injection must be ignored');
        $this->assertNull($fresh->voiceflow_project_id, 'credential injection must be ignored');
        $this->assertSame('cloned-env-xyz', $fresh->voiceflow_environment, 'env id should not be overwritten');
    }

    public function test_managed_wizard_skips_step_2(): void
    {
        $this->withMiddleware(RequireAgent::class);

        Http::fake([
            'realtime-api.voiceflow.com/v1alpha1/project/*/environment' => Http::response(['_id' => 'cloned-env-xyz'], 201),
        ]);

        $user = User::factory()->withPersonalTeam()->create();

        // POSTing to onboarding/start in managed mode lands directly on Done,
        // not on Connect. That's the visible "we handle everything" promise.
        $this->actingAs($user)
            ->post(route('onboarding.start'), ['name' => 'My agent'])
            ->assertRedirect(route('onboarding.done'));

        $this->assertSame(Agent::MODE_MANAGED, $user->currentTeam->fresh()->currentAgent->mode);
    }
}
