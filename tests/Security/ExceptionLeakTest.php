<?php

namespace Tests\Security;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Category F — exception hygiene.
 *
 * With APP_DEBUG=false (production posture) error responses must not
 * leak internals: no file paths, no stack traces, no exception class
 * names in the JSON bodies the handler renders.
 */
class ExceptionLeakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
    }

    public function test_json_404_does_not_leak_file_or_trace(): void
    {
        $user = $this->userWithAgent();

        // Route-model-binding miss → ModelNotFound → framework-rendered 404.
        $response = $this->actingAs($user)
            ->getJson(route('leads.show', 999999))
            ->assertNotFound();

        $json = (array) $response->json();
        $this->assertArrayNotHasKey('file', $json);
        $this->assertArrayNotHasKey('trace', $json);
        $this->assertArrayNotHasKey('exception', $json);
        $this->assertArrayNotHasKey('line', $json);
    }

    public function test_validation_422_does_not_leak_file_or_trace(): void
    {
        $user = $this->userWithAgent();

        // Missing user_id + message → ValidationException → 422 JSON.
        $response = $this->actingAs($user)
            ->postJson(route('chat.interact'), [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);

        $json = (array) $response->json();
        $this->assertArrayNotHasKey('file', $json);
        $this->assertArrayNotHasKey('trace', $json);
        $this->assertArrayNotHasKey('exception', $json);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function userWithAgent(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $user->currentTeam->forceFill([
            'current_agent_id' => $agent->id,
            'credit_balance' => 100, // chat.interact pre-checks credits before validating
        ])->save();

        return $user->fresh();
    }
}
