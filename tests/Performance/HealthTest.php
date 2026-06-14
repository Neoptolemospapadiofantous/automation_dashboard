<?php

namespace Tests\Performance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /api/health probe (strategy G5): the uptime monitor's view of the app.
 * Happy path only — simulating a dead cache/DB store reliably without
 * destabilising the rest of the suite is deferred to the resilience
 * suite (G3); the controller's per-component try/catch is the degraded
 * path's implementation.
 */
class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_reports_ok_with_db_and_cache_up(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'checks' => ['db' => 'ok', 'cache' => 'ok'],
            ]);
    }

    public function test_health_requires_no_authentication(): void
    {
        // No actingAs() anywhere in this test: an external uptime checker
        // has no session. A redirect-to-login or 401 here would silently
        // break monitoring.
        $this->assertGuest();

        $this->getJson(route('health'))->assertOk();
    }
}
