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

    public function test_update_persists_welcome_message_and_starter_prompts(): void
    {
        [$user, $agent] = $this->ownerWithCurrentAgent();

        $this->actingAs($user)
            ->put(route('install.update'), $this->validPayload([
                'welcome_message' => 'Hi there! How can I help?',
                'starter_prompts' => ['Pricing', 'Book a demo', 'Talk to sales'],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $config = $agent->fresh()->widgetConfig();

        $this->assertSame('Hi there! How can I help?', $config['welcome_message']);
        $this->assertSame(['Pricing', 'Book a demo', 'Talk to sales'], $config['starter_prompts']);
    }

    public function test_update_rejects_more_than_six_starter_prompts(): void
    {
        [$user, $agent] = $this->ownerWithCurrentAgent();
        $original = $agent->widget_config;

        $this->actingAs($user)
            ->put(route('install.update'), $this->validPayload([
                'starter_prompts' => ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
            ]))
            ->assertSessionHasErrors('starter_prompts');

        $this->assertSame($original, $agent->fresh()->widget_config);
    }

    public function test_update_rejects_a_starter_prompt_over_120_chars(): void
    {
        [$user, $agent] = $this->ownerWithCurrentAgent();
        $original = $agent->widget_config;

        $this->actingAs($user)
            ->put(route('install.update'), $this->validPayload([
                'starter_prompts' => [str_repeat('x', 121)],
            ]))
            ->assertSessionHasErrors('starter_prompts.0');

        $this->assertSame($original, $agent->fresh()->widget_config);
    }

    public function test_widget_config_drops_blank_starter_prompts(): void
    {
        [, $agent] = $this->ownerWithCurrentAgent();

        // Blank / whitespace-only entries are dropped (and the list trimmed +
        // capped at 6) by Agent::widgetConfig(), independent of the request
        // layer — the HTTP middleware (TrimStrings + ConvertEmptyStringsToNull)
        // would never let a blank string reach the controller anyway, so assert
        // the normalization directly on stored config.
        $agent->forceFill(['widget_config' => [
            'starter_prompts' => ['Pricing', '   ', '', "\t", 'Demo'],
        ]])->save();

        $this->assertSame(['Pricing', 'Demo'], $agent->fresh()->widgetConfig()['starter_prompts']);
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
