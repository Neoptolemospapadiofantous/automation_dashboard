<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AutomationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationActivityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Agent} */
    private function userWithAgent(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        return [$user, $agent];
    }

    private function makeRun(int $teamId, int $agentId, string $status, int $credits = 1): AutomationRun
    {
        return AutomationRun::create([
            'team_id' => $teamId,
            'agent_id' => $agentId,
            'action' => 'lookup_order',
            'mode' => AutomationRun::MODE_SYNC,
            'status' => $status,
            'idempotency_key' => bin2hex(random_bytes(8)),
            'credits_charged' => $credits,
        ]);
    }

    public function test_index_lists_current_agent_runs_with_summary(): void
    {
        [$user, $agent] = $this->userWithAgent();
        $this->makeRun($user->currentTeam->id, $agent->id, AutomationRun::STATUS_SUCCESS, 3);
        $this->makeRun($user->currentTeam->id, $agent->id, AutomationRun::STATUS_SUCCESS, 2);
        $this->makeRun($user->currentTeam->id, $agent->id, AutomationRun::STATUS_FAILED, 0);

        $this->actingAs($user)->get(route('agents.activity.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Agents/Activity')
                ->has('runs.data', 3)
                ->where('summary.total', 3)
                ->where('summary.success', 2)
                ->where('summary.success_rate', 67)
                ->where('summary.credits_charged', 5)
            );
    }

    public function test_filters_by_status(): void
    {
        [$user, $agent] = $this->userWithAgent();
        $this->makeRun($user->currentTeam->id, $agent->id, AutomationRun::STATUS_SUCCESS);
        $this->makeRun($user->currentTeam->id, $agent->id, AutomationRun::STATUS_BLOCKED);

        $this->actingAs($user)->get(route('agents.activity.index', ['status' => 'blocked']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('runs.data', 1)
                ->where('runs.data.0.status', 'blocked')
            );
    }

    public function test_does_not_leak_other_teams_runs(): void
    {
        [$user, $agent] = $this->userWithAgent();
        $this->makeRun($user->currentTeam->id, $agent->id, AutomationRun::STATUS_SUCCESS);

        $foreignAgent = Agent::factory()->create();
        $this->makeRun($foreignAgent->team_id, $foreignAgent->id, AutomationRun::STATUS_SUCCESS);

        $this->actingAs($user)->get(route('agents.activity.index'))
            ->assertInertia(fn ($page) => $page->has('runs.data', 1));
    }
}
