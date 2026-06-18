<?php

namespace Tests\Feature\Embed;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Install page save endpoint: persist widget appearance/behavior +
 * the domain allowlist for the team's current agent, with validation and
 * domain normalization.
 */
class InstallWidgetConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_persists_widget_config_and_normalizes_domains(): void
    {
        [$user, $agent] = $this->ownerWithCurrentAgent();

        $this->actingAs($user)
            ->put(route('install.update'), $this->validPayload([
                'accent_color' => '#123456',
                'allowed_domains' => ['https://Acme.com/foo', 'acme.com'],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $agent->refresh();

        $this->assertSame('#123456', $agent->widgetConfig()['accent_color']);
        $this->assertSame('left', $agent->widgetConfig()['position']);

        // scheme/path/port stripped, lowercased, deduped.
        $this->assertSame(['acme.com'], $agent->allowedDomains());
    }

    public function test_update_rejects_invalid_accent_color(): void
    {
        [$user, $agent] = $this->ownerWithCurrentAgent();
        $original = $agent->widget_config;

        $this->actingAs($user)
            ->put(route('install.update'), $this->validPayload([
                'accent_color' => 'red',
            ]))
            ->assertSessionHasErrors('accent_color');

        // Nothing saved.
        $this->assertSame($original, $agent->fresh()->widget_config);
    }

    public function test_update_404s_without_a_current_agent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        // No current agent configured on the team.

        $this->actingAs($user)
            ->put(route('install.update'), $this->validPayload())
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'accent_color' => '#000000',
            'text_color' => '#FFFFFF',
            'position' => 'left',
            'launcher_text' => 'Chat',
            'title' => 'Support',
            'subtitle' => 'AI assistant',
            'avatar_url' => 'https://acme.com/avatar.png',
            'proactive_message' => 'Need help?',
            'proactive_delay' => 8,
            'auto_open' => false,
            'show_branding' => true,
            'allowed_domains' => ['acme.com'],
        ], $overrides);
    }

    /**
     * @return array{0: User, 1: Agent}
     */
    private function ownerWithCurrentAgent(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        return [$user->fresh(), $agent];
    }
}
