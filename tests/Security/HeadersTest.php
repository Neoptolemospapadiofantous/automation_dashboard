<?php

namespace Tests\Security;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->get('/')
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
}
