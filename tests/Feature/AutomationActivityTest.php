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

    private function makeRun(int $teamId, int $agentId, string $status, int $credits = 1, string $action = 'lookup_order', ?string $actionId = null): AutomationRun
    {
        return AutomationRun::create([
            'team_id' => $teamId,
            'agent_id' => $agentId,
            'action' => $action,
            'action_id' => $actionId,
            'mode' => AutomationRun::MODE_SYNC,
            'status' => $status,
            'idempotency_key' => bin2hex(random_bytes(8)),
            'credits_charged' => $credits,
        ]);
    }

    public function test_automations_flag_is_shared_to_pages_for_the_nav_gate(): void
    {
        // The sidebar's Activity link renders on `v-if="automationsEnabled"`,
        // driven by this shared prop. If the prop stops being shared the link
        // silently never appears even once the flag flips — guard it here.
        [$user] = $this->userWithAgent();

        config()->set('runtime.automation.enabled', false);
        $this->actingAs($user)->get(route('agents.activity.index'))
            ->assertInertia(fn ($page) => $page->where('automationsEnabled', false));

        config()->set('runtime.automation.enabled', true);
        $this->actingAs($user)->get(route('agents.activity.index'))
            ->assertInertia(fn ($page) => $page->where('automationsEnabled', true));
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

    public function test_action_filter_groups_renamed_action_by_stable_id(): void
    {
        [$user, $agent] = $this->userWithAgent();
        $teamId = $user->currentTeam->id;
        $id = '01JZSTABLEID00000000000000';

        // Same action before and after a rename, plus an unrelated one.
        $this->makeRun($teamId, $agent->id, AutomationRun::STATUS_SUCCESS, action: 'old_name', actionId: $id);
        $this->makeRun($teamId, $agent->id, AutomationRun::STATUS_FAILED, action: 'new_name', actionId: $id);
        $this->makeRun($teamId, $agent->id, AutomationRun::STATUS_SUCCESS, action: 'other', actionId: '01JZOTHERID000000000000000');

        // Filtering by the stable id spans the rename.
        $this->actingAs($user)->get(route('agents.activity.index', ['action' => $id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('runs.data', 2)
                // One option for the renamed action, labelled with its latest name.
                ->has('actionOptions', 2)
                ->where('actionOptions.0.value', $id)
                ->where('actionOptions.0.label', 'new_name')
            );
    }

    public function test_action_filter_falls_back_to_name_for_pre_id_runs(): void
    {
        [$user, $agent] = $this->userWithAgent();
        $this->makeRun($user->currentTeam->id, $agent->id, AutomationRun::STATUS_SUCCESS, action: 'legacy_action');

        $this->actingAs($user)->get(route('agents.activity.index', ['action' => 'legacy_action']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('runs.data', 1)
                ->where('actionOptions.0.value', 'legacy_action')
                ->where('actionOptions.0.label', 'legacy_action')
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
