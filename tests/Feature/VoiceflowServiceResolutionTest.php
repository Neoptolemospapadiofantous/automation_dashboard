<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use App\Services\VoiceflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

/**
 * Confirms the per-request VoiceflowService binding from AppServiceProvider
 * actually delivers the correct tenant's credentials to downstream code.
 *
 * The previous architecture was implicit: every controller called
 * config('services.voiceflow.api_key') and got the global value. This test
 * is the regression boundary that says: never go back.
 */
class VoiceflowServiceResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_with_current_team_agent_credentials(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'voiceflow_api_key' => 'VF.DM.tenant-a-key',
            'voiceflow_project_id' => 'project-aaa',
            'voiceflow_environment' => 'main',
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        $this->actingAs($user->fresh());

        $service = app(VoiceflowService::class);

        $this->assertSame('VF.DM.tenant-a-key', $this->readPrivate($service, 'apiKey'));
        $this->assertSame('project-aaa', $this->readPrivate($service, 'projectId'));
    }

    public function test_falls_back_to_env_config_when_no_current_agent(): void
    {
        // Background jobs and artisan commands run unauthenticated; the
        // service must still be resolvable from .env config so transcript
        // backfill / KB sync / cron tasks keep working unchanged.
        config()->set('services.voiceflow.api_key', 'VF.DM.env-fallback');
        config()->set('services.voiceflow.project_id', 'env-proj');

        $service = app(VoiceflowService::class);

        $this->assertSame('VF.DM.env-fallback', $this->readPrivate($service, 'apiKey'));
    }

    public function test_isolates_tenants_via_the_health_endpoint(): void
    {
        // End-to-end: act as Alice, hit /agent/health, confirm Alice's
        // project_id surfaces (proving downstream code received Alice's
        // agent's credentials, not the .env fallback or some shared cache).
        $alice = User::factory()->withPersonalTeam()->create();
        $aliceAgent = Agent::factory()->for($alice->currentTeam)->create([
            'voiceflow_api_key' => 'VF.DM.alice',
            'voiceflow_project_id' => 'alice-proj',
        ]);
        $alice->currentTeam->forceFill(['current_agent_id' => $aliceAgent->id])->save();

        Http::fake([
            '*' => Http::response(['sessionKey' => 'a'], 200),
        ]);

        $this->actingAs($alice->fresh())->getJson(route('chat.health'))
            ->assertOk()
            ->assertJsonPath('project_id', 'alice-proj');
    }

    public function test_request_with_no_agent_uses_env_fallback_in_health(): void
    {
        // Mirror image: a user whose team has no current_agent_id falls back
        // to the .env-configured creds. Proves the scoped binding's
        // null-coalesce branch is reached, not an exception thrown.
        config()->set('services.voiceflow.api_key', 'VF.DM.env-key');
        config()->set('services.voiceflow.project_id', 'env-project');

        $user = User::factory()->withPersonalTeam()->create();
        // No current_agent_id set.

        Http::fake([
            '*' => Http::response(['sessionKey' => 'k'], 200),
        ]);

        $this->actingAs($user->fresh())->getJson(route('chat.health'))
            ->assertOk()
            ->assertJsonPath('project_id', 'env-project');
    }

    public function test_for_agent_factory_returns_agent_scoped_instance(): void
    {
        // Direct test of the canonical entrypoint, independent of the container.
        $agent = Agent::factory()->create([
            'voiceflow_api_key' => 'VF.DM.direct',
            'voiceflow_project_id' => 'direct-proj',
            'voiceflow_environment' => 'staging',
        ]);

        $service = VoiceflowService::forAgent($agent);

        $this->assertSame('VF.DM.direct', $this->readPrivate($service, 'apiKey'));
        $this->assertSame('direct-proj', $this->readPrivate($service, 'projectId'));
        $this->assertSame('staging', $this->readPrivate($service, 'environment'));
    }

    /**
     * Read a private property — only used here because the alternative is
     * making credential properties public, which would leak them to anything
     * dumping the service.
     */
    protected function readPrivate(object $obj, string $property): mixed
    {
        $ref = new ReflectionClass($obj);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($obj);
    }
}
