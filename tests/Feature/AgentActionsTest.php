<?php

namespace Tests\Feature;

use App\Actions\Agents\CreateAgent;
use App\Actions\Agents\DeleteAgent;
use App\Actions\Agents\RotateWebhookSecret;
use App\Actions\Agents\SwitchAgent;
use App\Actions\Agents\UpdateAgentCredentials;
use App\Events\Domain\AgentActivated;
use App\Events\Domain\AgentCreated;
use App\Events\Domain\AgentHealthChecked;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class AgentActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_agent_persists_and_fires_event_and_becomes_current(): void
    {
        Event::fake([AgentCreated::class]);

        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $agent = (new CreateAgent())->execute($team, 'Sales bot');

        $this->assertDatabaseHas('agents', ['id' => $agent->id, 'name' => 'Sales bot', 'status' => Agent::STATUS_DRAFT]);
        $this->assertSame($agent->id, $team->fresh()->current_agent_id, 'First agent becomes current');
        Event::assertDispatched(AgentCreated::class);
    }

    public function test_update_credentials_runs_health_and_activates_on_green(): void
    {
        Event::fake([AgentHealthChecked::class, AgentActivated::class]);

        // V4 health probe is two steps:
        //   1. POST /v4/project/{projectId}/environment/{env}/session -> { sessionKey }
        //   2. POST /v4/interact (with the sessionKey) -> { traces: [...] }
        Http::fake([
            'general-runtime.voiceflow.com/v4/project/*' => Http::response(['sessionKey' => 'sess-test-123'], 200),
            'general-runtime.voiceflow.com/v4/interact' => Http::response(['traces' => []], 200),
        ]);

        // Service-level config must look configured for VoiceflowService::health()
        // to actually probe in this Phase A test. Once Phase B refactors to
        // VoiceflowService::forAgent($agent), this stops being needed.
        config()->set('services.voiceflow.api_key', 'VF.DM.config');
        config()->set('services.voiceflow.project_id', 'proj-cfg');

        $agent = Agent::factory()->unconfigured()->create();

        ['agent' => $updated, 'health' => $health] = (new UpdateAgentCredentials())->execute($agent, [
            'voiceflow_api_key' => 'VF.DM.fresh',
            'voiceflow_project_id' => 'proj-1',
        ]);

        $this->assertTrue((bool) $updated->last_health_ok);
        $this->assertSame(Agent::STATUS_ACTIVE, $updated->status);
        Event::assertDispatched(AgentHealthChecked::class);
        Event::assertDispatched(AgentActivated::class);
    }

    public function test_update_credentials_health_checks_the_specific_agent_not_the_current(): void
    {
        // Regression: UpdateAgentCredentials::runHealthCheck must build the
        // service from the $agent argument, not from the container's scoped
        // binding. If it grabs the current agent by mistake, a user
        // updating a *non-current* agent from settings will health-check
        // the wrong tenant and either falsely pass or falsely fail.
        Event::fake([AgentHealthChecked::class]);

        $user = User::factory()->withPersonalTeam()->create();

        // The CURRENT agent: configured + healthy. If the binding's
        // resolution leaks through, the health probe would hit current-proj.
        $current = Agent::factory()->for($user->currentTeam)->create([
            'status' => Agent::STATUS_ACTIVE,
            'voiceflow_api_key' => 'VF.DM.current-key',
            'voiceflow_project_id' => 'current-proj',
            'last_health_ok' => true,
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $current->id])->save();

        // The agent we're saving creds for — different project_id. The
        // health probe below MUST hit "other-proj", not "current-proj".
        $other = Agent::factory()->draft()->for($user->currentTeam)->create();

        Http::fake([
            'general-runtime.voiceflow.com/v4/project/other-proj/*' => Http::response(['sessionKey' => 's'], 200),
            'general-runtime.voiceflow.com/v4/project/current-proj/*' => Http::response(['error' => 'should not be hit'], 500),
            'general-runtime.voiceflow.com/v4/interact' => Http::response(['traces' => []], 200),
        ]);

        $this->actingAs($user->fresh());

        ['agent' => $updated, 'health' => $health] = (new UpdateAgentCredentials())->execute($other, [
            'voiceflow_api_key' => 'VF.DM.other',
            'voiceflow_project_id' => 'other-proj',
        ]);

        $this->assertTrue((bool) $health['ok'], 'Health probe must use the passed agent, not the current one');
        $this->assertSame(Agent::STATUS_ACTIVE, $updated->status);
    }

    public function test_update_credentials_does_not_activate_when_health_fails(): void
    {
        Event::fake([AgentActivated::class]);

        // Health probe fails — agent stays draft, no activation event.
        Http::fake([
            'general-runtime.voiceflow.com/*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        config()->set('services.voiceflow.api_key', 'VF.DM.config');
        config()->set('services.voiceflow.project_id', 'proj-cfg');

        $agent = Agent::factory()->unconfigured()->create();

        ['agent' => $updated] = (new UpdateAgentCredentials())->execute($agent, [
            'voiceflow_api_key' => 'VF.DM.bad',
            'voiceflow_project_id' => 'proj-x',
        ]);

        $this->assertFalse((bool) $updated->last_health_ok);
        $this->assertSame(Agent::STATUS_DRAFT, $updated->status);
        Event::assertNotDispatched(AgentActivated::class);
    }

    public function test_rotate_webhook_secret_generates_a_new_value(): void
    {
        $agent = Agent::factory()->create();
        $before = $agent->webhook_secret;

        $after = (new RotateWebhookSecret())->execute($agent)->webhook_secret;

        $this->assertNotSame($before, $after);
        $this->assertGreaterThanOrEqual(40, strlen($after));
    }

    public function test_switch_agent_rejects_foreign_agent(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $mine = Agent::factory()->for($team)->create();
        $foreign = Agent::factory()->create(); // different team

        (new SwitchAgent())->execute($team, $mine);
        $this->assertSame($mine->id, $team->fresh()->current_agent_id);

        $this->expectException(InvalidArgumentException::class);
        (new SwitchAgent())->execute($team, $foreign);
    }

    public function test_delete_agent_falls_back_current_agent_to_another_team_agent(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $a = Agent::factory()->for($team)->create();
        $b = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $a->id])->save();

        (new DeleteAgent())->execute($a);

        $this->assertSame($b->id, $team->fresh()->current_agent_id);
        $this->assertDatabaseMissing('agents', ['id' => $a->id]);
    }

    public function test_delete_only_agent_nulls_current(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $only = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $only->id])->save();

        (new DeleteAgent())->execute($only);

        $this->assertNull($team->fresh()->current_agent_id);
    }
}
