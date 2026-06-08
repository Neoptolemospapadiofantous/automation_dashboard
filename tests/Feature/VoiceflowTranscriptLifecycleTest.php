<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceflowTranscriptLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.voiceflow.analytics_url', 'https://analytics.voiceflow.test');
    }

    private function setupTeamWithAgent(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create([
            'voiceflow_api_key' => 'VF.DM.test',
            'voiceflow_workspace_api_key' => 'VF.WS.test',
            'voiceflow_project_id' => 'proj-1',
            'voiceflow_environment' => 'main',
            'status' => 'active',
        ]);
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        return [$user->fresh(), $team->fresh(), $agent];
    }

    public function test_end_upstream_calls_voiceflow_end_endpoint_and_marks_local_ended(): void
    {
        [$user, $team, $agent] = $this->setupTeamWithAgent();

        $convo = Conversation::factory()->for($team)->create([
            'agent_id' => $agent->id,
            'voiceflow_user_id' => 'u-1',
            'voiceflow_transcript_id' => 't-99',
            'status' => 'active',
            'ended_at' => null,
        ]);

        Http::fake([
            'analytics.voiceflow.test/v1/transcript/t-99/project/*/end' => Http::response('ok', 200),
        ]);

        $this->actingAs($user)
            ->post(route('conversations.end-upstream', $convo))
            ->assertRedirect();

        Http::assertSentCount(1);
        $this->assertNotNull($convo->fresh()->ended_at);
        $this->assertSame('ended', $convo->fresh()->status);
    }

    public function test_end_upstream_rejects_without_transcript_id(): void
    {
        [$user, $team, $agent] = $this->setupTeamWithAgent();

        $convo = Conversation::factory()->for($team)->create([
            'agent_id' => $agent->id,
            'voiceflow_user_id' => 'u-1',
            'voiceflow_transcript_id' => null,
        ]);

        $this->actingAs($user)
            ->post(route('conversations.end-upstream', $convo))
            ->assertSessionHasErrors('transcript');
    }

    public function test_delete_upstream_calls_voiceflow_delete_and_removes_local(): void
    {
        [$user, $team, $agent] = $this->setupTeamWithAgent();

        $convo = Conversation::factory()->for($team)->create([
            'agent_id' => $agent->id,
            'voiceflow_user_id' => 'u-1',
            'voiceflow_transcript_id' => 't-99',
        ]);

        Http::fake([
            'analytics.voiceflow.test/v1/transcript/t-99' => Http::response('', 204),
        ]);

        $this->actingAs($user)
            ->delete(route('conversations.delete-upstream', $convo))
            ->assertRedirect(route('conversations.index'));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/v1/transcript/t-99'));

        $this->assertDatabaseMissing('conversations', ['id' => $convo->id]);
    }

    public function test_delete_upstream_rejects_cross_tenant_request(): void
    {
        [$user, $team, $agent] = $this->setupTeamWithAgent();
        [$other] = $this->setupTeamWithAgent();

        $convo = Conversation::factory()->for($team)->create([
            'agent_id' => $agent->id,
            'voiceflow_user_id' => 'u-1',
            'voiceflow_transcript_id' => 't-99',
        ]);

        $this->actingAs($other)
            ->delete(route('conversations.delete-upstream', $convo))
            ->assertForbidden();

        $this->assertDatabaseHas('conversations', ['id' => $convo->id]);
    }
}
