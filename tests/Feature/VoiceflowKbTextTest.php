<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceflowKbTextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.voiceflow.realtime_url', 'https://realtime.voiceflow.test');
    }

    public function test_store_text_posts_to_voiceflow_with_type_text(): void
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

        Http::fake([
            'realtime.voiceflow.test/v1alpha1/public/knowledge-base/document' => Http::response(
                ['data' => ['documentID' => 'doc-text-1']],
                201,
            ),
        ]);

        $this->actingAs($user->fresh())
            ->post(route('knowledge.text'), [
                'name' => 'House rules',
                'text' => 'Always greet the customer by name.',
            ])
            ->assertRedirect();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/knowledge-base/document')
            && ($request['data']['type'] ?? null) === 'text'
            && ($request['data']['name'] ?? null) === 'House rules'
            && ($request['data']['text'] ?? null) === 'Always greet the customer by name.'
            && $request->header('authorization')[0] === 'VF.WS.test');
    }

    public function test_store_text_validates_required_fields(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create([
            'voiceflow_api_key' => 'VF.DM.test',
            'voiceflow_workspace_api_key' => 'VF.WS.test',
            'voiceflow_project_id' => 'proj-1',
            'status' => 'active',
        ]);
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        $this->actingAs($user->fresh())
            ->post(route('knowledge.text'), [])
            ->assertSessionHasErrors(['name', 'text']);
    }
}
