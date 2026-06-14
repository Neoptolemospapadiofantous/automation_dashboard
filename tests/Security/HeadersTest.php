<?php

namespace Tests\Security;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\Contracts\Runtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Category F — security headers (SecurityHeaders middleware).
 *
 * Every dashboard response must carry the baseline headers; the embed
 * chat page must NOT be locked down — it's an iframe product and keeps
 * its `frame-ancestors *` CSP + ALLOWALL set by EmbedController.
 */
class HeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_dashboard_response_has_security_headers(): void
    {
        $user = $this->userWithAgent();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_guest_page_has_security_headers(): void
    {
        // Root now redirects; use the login page as the guest 200 surface.
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_embed_chat_page_stays_frameable_from_any_site(): void
    {
        $agent = Agent::factory()->create(['status' => Agent::STATUS_ACTIVE]);

        $response = $this->get("/embed/{$agent->slug}")->assertOk();

        // The middleware must NOT override the controller's framing headers.
        $this->assertStringContainsString(
            'frame-ancestors *',
            (string) $response->headers->get('Content-Security-Policy'),
        );
        $response->assertHeader('X-Frame-Options', 'ALLOWALL');

        // The non-framing baseline headers still apply.
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function userWithAgent(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        return $user->fresh();
    }

    /**
     * EU AI Act Art. 50: the AI disclosure is rendered by the PLATFORM at
     * every conversation surface, independent of agent scripting.
     */
    public function test_embed_chat_carries_the_ai_disclosure(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);

        $this->get("/embed/{$agent->slug}")
            ->assertOk()
            ->assertSee('AI assistant — not a person', false)
            ->assertSee('ask for a human', false);
    }

    public function test_engine_prompt_forbids_claiming_to_be_human(): void
    {
        config(['runtime.llm.anthropic.api_key' => 'sk-test']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hi!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ]),
        ]);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);

        app(Runtime::class)->launch($agent, 'v-art50');

        Http::assertSent(function ($request): bool {
            $system = (string) $request->data()['system'];

            return str_contains($system, 'never claim to be human')
                || str_contains($system, 'You are an AI assistant');
        });
    }
}
