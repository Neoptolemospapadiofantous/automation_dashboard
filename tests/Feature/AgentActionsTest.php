<?php

namespace Tests\Feature;

use App\Actions\Agents\CreateAgent;
use App\Actions\Agents\DeleteAgent;
use App\Actions\Agents\SwitchAgent;
use App\Billing\Exceptions\PlanLimitExceeded;
use App\Events\Domain\AgentCreated;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class AgentActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_agent_is_native_active_and_becomes_current(): void
    {
        Event::fake([AgentCreated::class]);

        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $agent = (new CreateAgent)->execute($team, 'Sales bot');

        $this->assertDatabaseHas('agents', [
            'id' => $agent->id,
            'name' => 'Sales bot',
            // Native runtime: nothing external to provision — agents are
            // live the moment the row exists.
            'status' => Agent::STATUS_ACTIVE,
            'runtime_mode' => Agent::RUNTIME_NATIVE,
            'mode' => Agent::MODE_MANAGED,
        ]);
        $this->assertSame($agent->id, $team->fresh()->current_agent_id, 'First agent becomes current');
        Event::assertDispatched(AgentCreated::class);
    }

    public function test_create_agent_enforces_plan_limit(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        // Starter (Plan::Free) allows 1 agent.
        (new CreateAgent)->execute($team, 'First');

        $this->expectException(PlanLimitExceeded::class);
        (new CreateAgent)->execute($team, 'Second');
    }

    public function test_switch_agent_rejects_foreign_agent(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $mine = Agent::factory()->for($team)->create();
        $foreign = Agent::factory()->create(); // different team

        (new SwitchAgent)->execute($team, $mine);
        $this->assertSame($mine->id, $team->fresh()->current_agent_id);

        $this->expectException(InvalidArgumentException::class);
        (new SwitchAgent)->execute($team, $foreign);
    }

    public function test_delete_agent_falls_back_current_agent_to_another_team_agent(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $a = Agent::factory()->for($team)->create();
        $b = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $a->id])->save();

        (new DeleteAgent)->execute($a);

        $this->assertSame($b->id, $team->fresh()->current_agent_id);
        $this->assertDatabaseMissing('agents', ['id' => $a->id]);
    }

    public function test_delete_only_agent_nulls_current(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $only = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $only->id])->save();

        (new DeleteAgent)->execute($only);

        $this->assertNull($team->fresh()->current_agent_id);
    }
}
