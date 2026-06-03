<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Server-side enforcement of the Voiceflow credential format rules. The
 * frontend mirrors these for UX, but this is the authoritative boundary —
 * a hand-crafted POST that skips the JS validation is still rejected here.
 *
 * Backend rules live in OnboardingController::credentialRules() and are
 * shared with AgentController::update().
 */
class CredentialValidationTest extends TestCase
{
    use RefreshDatabase;

    private function userWithDraftAgent(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->draft()->for($user->currentTeam)->create();

        return [$user, $agent];
    }

    // --- Onboarding (creds are required) ----------------------------------

    public function test_onboarding_rejects_email_as_project_id(): void
    {
        // The exact failure mode that prompted these rules: user pastes
        // their email into the project ID field.
        [$user] = $this->userWithDraftAgent();

        $this->actingAs($user)->post(route('onboarding.save'), [
            'voiceflow_api_key' => 'VF.DM.1234567890abcdef12345678.abc',
            'voiceflow_project_id' => 'someone@example.com',
        ])->assertSessionHasErrors('voiceflow_project_id');
    }

    public function test_onboarding_rejects_non_vf_dm_api_key(): void
    {
        [$user] = $this->userWithDraftAgent();

        $this->actingAs($user)->post(route('onboarding.save'), [
            'voiceflow_api_key' => 'sk-totally-wrong-prefix',
            'voiceflow_project_id' => '1234567890abcdef12345678',
        ])->assertSessionHasErrors('voiceflow_api_key');
    }

    public function test_onboarding_rejects_short_project_id(): void
    {
        [$user] = $this->userWithDraftAgent();

        $this->actingAs($user)->post(route('onboarding.save'), [
            'voiceflow_api_key' => 'VF.DM.abcdef.ghijkl',
            'voiceflow_project_id' => 'too-short',
        ])->assertSessionHasErrors('voiceflow_project_id');
    }

    public function test_onboarding_rejects_invalid_workspace_key_format(): void
    {
        [$user] = $this->userWithDraftAgent();

        $this->actingAs($user)->post(route('onboarding.save'), [
            'voiceflow_api_key' => 'VF.DM.1234567890abcdef12345678.abc',
            'voiceflow_project_id' => '1234567890abcdef12345678',
            'voiceflow_workspace_api_key' => 'test', // not VF.*
        ])->assertSessionHasErrors('voiceflow_workspace_api_key');
    }

    public function test_onboarding_accepts_valid_format_credentials(): void
    {
        // Reaches the health check (which talks to Voiceflow). Faking it
        // green keeps the test focused on validation — we only assert there
        // are no error bag entries for the credential fields.
        Http::fake([
            'general-runtime.voiceflow.com/v4/project/*' => Http::response(['sessionKey' => 's'], 200),
            'general-runtime.voiceflow.com/v4/interact' => Http::response(['traces' => []], 200),
        ]);

        [$user] = $this->userWithDraftAgent();

        $this->actingAs($user)->post(route('onboarding.save'), [
            'voiceflow_api_key' => 'VF.DM.1234567890abcdef12345678.abcdEFG',
            'voiceflow_project_id' => '1234567890abcdef12345678',
            'voiceflow_environment' => 'main',
        ])->assertSessionDoesntHaveErrors(['voiceflow_api_key', 'voiceflow_project_id', 'voiceflow_environment']);
    }

    public function test_onboarding_rejects_garbage_environment(): void
    {
        [$user] = $this->userWithDraftAgent();

        $this->actingAs($user)->post(route('onboarding.save'), [
            'voiceflow_api_key' => 'VF.DM.1234567890abcdef12345678.abc',
            'voiceflow_project_id' => '1234567890abcdef12345678',
            'voiceflow_environment' => 'has spaces and / slashes',
        ])->assertSessionHasErrors('voiceflow_environment');
    }

    // --- Agent update (creds are optional — leave blank to keep) ----------

    public function test_agent_update_allows_empty_credentials(): void
    {
        [$user, $agent] = $this->userWithDraftAgent();

        $this->actingAs($user)->put(route('agents.update', $agent), [
            'name' => 'Renamed',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame('Renamed', $agent->fresh()->name);
    }

    public function test_agent_update_still_validates_format_when_present(): void
    {
        [$user, $agent] = $this->userWithDraftAgent();

        $this->actingAs($user)->put(route('agents.update', $agent), [
            'voiceflow_project_id' => 'not-a-hex-string',
        ])->assertSessionHasErrors('voiceflow_project_id');
    }
}
