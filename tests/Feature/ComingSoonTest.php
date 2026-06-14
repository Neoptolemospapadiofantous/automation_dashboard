<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Pre-launch gate (ComingSoon middleware). When config('app.coming_soon') is
 * true, user-facing web routes render the coming-soon page and auth is blocked;
 * machine endpoints stay reachable. Off → normal app.
 */
class ComingSoonTest extends TestCase
{
    public function test_gate_on_shows_coming_soon_for_landing_and_login(): void
    {
        config(['app.coming_soon' => true]);

        $this->get('/')->assertOk()->assertSee('Coming soon');
        $this->get('/login')->assertOk()->assertSee('Coming soon')->assertDontSee('Forgot your password');
    }

    public function test_gate_on_leaves_machine_endpoints_open(): void
    {
        config(['app.coming_soon' => true]);

        // Health route is not gated (would be the coming-soon page if it were).
        $this->get('/up')->assertDontSee('Coming soon');
        $this->get('/api/health')->assertDontSee('Coming soon');
    }

    public function test_gate_off_serves_the_normal_app(): void
    {
        config(['app.coming_soon' => false]);

        $this->get('/login')->assertOk()->assertDontSee('Coming soon');
    }
}
