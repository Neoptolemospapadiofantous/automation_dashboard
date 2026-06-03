<?php

namespace Tests\Feature;

use App\Actions\Agents\CreateAgent;
use App\Actions\Agents\DeleteAgent;
use App\Models\Agent;
use App\Models\User;
use App\Models\VoiceflowProjectPoolEntry;
use App\Provisioning\PoolAllocator;
use App\Provisioning\PoolExhausted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase K — Voiceflow project pool. Locks in the contract that managed
 * mode now allocates from the pre-created pool (not from environment
 * cloning, which Voiceflow's docs proved unsafe for multi-tenancy).
 */
class PoolAllocatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.voiceflow.managed.enabled', true);
    }

    public function test_allocate_picks_oldest_available_and_marks_it_assigned(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $agent = Agent::factory()->draft()->for($team)->create(['mode' => Agent::MODE_MANAGED]);

        // Two available rows + one already retired. Allocator should pick
        // the lower-id available one (FIFO).
        $first = VoiceflowProjectPoolEntry::factory()->create();
        $second = VoiceflowProjectPoolEntry::factory()->create();
        VoiceflowProjectPoolEntry::factory()->retired()->create();

        $entry = app(PoolAllocator::class)->allocate($agent);

        $this->assertSame($first->id, $entry->id);
        $this->assertSame(VoiceflowProjectPoolEntry::STATUS_ASSIGNED, $entry->status);
        $this->assertSame($agent->id, $entry->assigned_to_agent_id);
        $this->assertNotNull($entry->assigned_at);
    }

    public function test_allocate_throws_pool_exhausted_when_no_available_entries(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $agent = Agent::factory()->draft()->for($team)->create(['mode' => Agent::MODE_MANAGED]);
        // Only assigned + retired rows exist.
        VoiceflowProjectPoolEntry::factory()->assigned()->create();
        VoiceflowProjectPoolEntry::factory()->retired()->create();

        $this->expectException(PoolExhausted::class);
        app(PoolAllocator::class)->allocate($agent);
    }

    public function test_create_managed_agent_uses_pool_and_stamps_credentials(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $entry = VoiceflowProjectPoolEntry::factory()->create([
            'voiceflow_project_id' => 'pooled-project-abc',
            'voiceflow_api_key' => 'VF.DM.pooled-key',
        ]);

        $agent = (new CreateAgent())->execute($user->currentTeam, 'Sales bot');

        $this->assertSame(Agent::MODE_MANAGED, $agent->mode);
        $this->assertSame(Agent::STATUS_ACTIVE, $agent->status);
        $this->assertSame('pooled-project-abc', $agent->voiceflow_project_id);
        $this->assertSame('VF.DM.pooled-key', $agent->voiceflow_api_key);

        // Pool entry now points at this agent.
        $entry->refresh();
        $this->assertSame(VoiceflowProjectPoolEntry::STATUS_ASSIGNED, $entry->status);
        $this->assertSame($agent->id, $entry->assigned_to_agent_id);
    }

    public function test_pool_exhausted_during_create_does_not_leave_orphan_agent(): void
    {
        // No pool entries — allocation will throw. The shell agent created
        // first should be deleted in CreateAgent's catch so we don't
        // accumulate half-provisioned rows after each capacity error.
        $user = User::factory()->withPersonalTeam()->create();
        $teamId = $user->currentTeam->id;

        try {
            (new CreateAgent())->execute($user->currentTeam, 'Will fail');
            $this->fail('Expected PoolExhausted');
        } catch (PoolExhausted) {
            $this->assertDatabaseMissing('agents', ['team_id' => $teamId]);
        }
    }

    public function test_pool_exhausted_returns_503_via_http(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user->fresh())
            ->postJson(route('onboarding.start'), ['name' => 'Will fail'])
            ->assertStatus(503)
            ->assertJsonStructure(['error', 'detail']);
    }

    public function test_delete_managed_agent_retires_pool_entry(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        VoiceflowProjectPoolEntry::factory()->create();

        $agent = (new CreateAgent())->execute($user->currentTeam, 'Sales bot');
        (new DeleteAgent())->execute($agent);

        $this->assertDatabaseHas('voiceflow_project_pool', [
            'voiceflow_project_id' => $agent->voiceflow_project_id,
            // Status is 'retired' not 'available' — KB + conversation state
            // are still on Voiceflow's side; reuse requires manual wipe.
            'status' => VoiceflowProjectPoolEntry::STATUS_RETIRED,
        ]);
    }

    public function test_pool_counts_reports_per_status(): void
    {
        VoiceflowProjectPoolEntry::factory()->count(3)->create();
        VoiceflowProjectPoolEntry::factory()->assigned()->count(2)->create();
        VoiceflowProjectPoolEntry::factory()->retired()->create();

        $counts = app(PoolAllocator::class)->counts();

        $this->assertSame(3, $counts['available']);
        $this->assertSame(2, $counts['assigned']);
        $this->assertSame(1, $counts['retired']);
        $this->assertSame(6, $counts['total']);
    }
}
