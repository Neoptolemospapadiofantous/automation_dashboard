<?php

namespace Tests\Feature;

use App\Actions\Agents\CreateAgent;
use App\Http\Middleware\RequireAgent;
use App\Models\Agent;
use App\Models\User;
use App\Models\VoiceflowProjectPoolEntry;
use App\Services\VoiceflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

    // NOTE on prior tests: this file previously had
    //   - test_create_agent_clones_environment_and_marks_active
    //   - test_managed_create_propagates_voiceflow_failure
    //   - test_managed_isconfigured_requires_env_config
    // All three exercised the now-removed environment-cloning path. The
    // managed path was rewritten in Phase K to allocate from a
    // pre-created project pool (Voiceflow's docs confirm the clone
    // approach was unsafe: 10-env cap + shared KB/integrations across
    // environments in the same project). Pool-path tests live in
    // PoolAllocatorTest. The remaining cases below still belong here —
    // they cover the "managed mode is fundamentally different from BYOK"
    // behavior independent of how provisioning happens.

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

        $service = VoiceflowService::forAgent($byok);
        $ref = new \ReflectionClass($service);
        $apiKey = $ref->getProperty('apiKey');
        $apiKey->setAccessible(true);

        $this->assertSame('VF.DM.byok-tenant', $apiKey->getValue($service),
            'BYOK agent must keep using its own key even when managed mode is on globally');
    }

    public function test_settings_page_hides_credentials_for_managed_agent(): void
    {
        // Phase K: managed agents DO carry real credentials (stamped from
        // the pool); the test is that the SETTINGS PAGE PROJECTION hides
        // them, not that the row has null values.
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'mode' => Agent::MODE_MANAGED,
            'voiceflow_api_key' => 'VF.DM.pool-stamped-secret',
            'voiceflow_project_id' => 'pool-stamped-project',
            'voiceflow_environment' => 'main',
            'voiceflow_workspace_api_key' => 'VF.WS.pool-stamped-ws',
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
        // Defence-in-depth: a clever curl trying to overwrite a managed
        // agent's pool-stamped credentials gets the name change but the
        // credential fields are dropped — keys can only change via
        // re-pool-allocation, never via the settings PUT.
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'mode' => Agent::MODE_MANAGED,
            'voiceflow_api_key' => 'VF.DM.original-pool-key',
            'voiceflow_project_id' => 'original-pool-project',
            'voiceflow_environment' => 'main',
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
        $this->assertSame('VF.DM.original-pool-key', $fresh->voiceflow_api_key, 'credential injection must not overwrite the pool-stamped key');
        $this->assertSame('original-pool-project', $fresh->voiceflow_project_id, 'credential injection must not overwrite the pool-stamped project_id');
        $this->assertSame('main', $fresh->voiceflow_environment, 'env id should not be overwritten');
    }

    public function test_managed_wizard_skips_step_2(): void
    {
        $this->withMiddleware(RequireAgent::class);

        // Seed a pool entry so allocation succeeds. Pool-empty case is
        // covered separately in PoolAllocatorTest::test_pool_exhausted_returns_503.
        VoiceflowProjectPoolEntry::factory()->create();

        $user = User::factory()->withPersonalTeam()->create();

        // POSTing to onboarding/start in managed mode lands directly on Done,
        // not on Connect. That's the visible "we handle everything" promise.
        $this->actingAs($user)
            ->post(route('onboarding.start'), ['name' => 'My agent'])
            ->assertRedirect(route('onboarding.done'));

        $this->assertSame(Agent::MODE_MANAGED, $user->currentTeam->fresh()->currentAgent->mode);
    }
}
