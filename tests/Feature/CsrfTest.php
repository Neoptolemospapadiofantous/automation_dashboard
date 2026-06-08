<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Verifies CSRF is wired the way we expect for the SaaS:
 * - Web POST endpoints (Inertia forms) have ValidateCsrfToken in their
 *   middleware pipeline → real cross-origin POSTs without a token are
 *   rejected with 419.
 * - The /api/voiceflow/lead-captured/{slug} webhook is in the api group
 *   and intentionally OUTSIDE CSRF — Voiceflow calls it cross-origin with
 *   no session. The per-agent X-Webhook-Secret header is the guard there.
 *
 * Laravel's test helpers auto-include the session CSRF token, so we
 * assert against the middleware *registry* rather than trying to fake a
 * cross-origin POST.
 */
class CsrfTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_middleware_is_registered_on_web_group(): void
    {
        $middleware = app(Kernel::class)->getMiddlewareGroups()['web'] ?? [];

        $this->assertContains(
            ValidateCsrfToken::class,
            $middleware,
            'ValidateCsrfToken must remain in the web middleware group — without it the Inertia forms are unprotected.'
        );
    }

    public function test_voiceflow_webhook_is_in_api_group_so_csrf_does_not_apply(): void
    {
        // The api group has its own (no-CSRF) pipeline. Confirm the route
        // we publish for Voiceflow lives there, not in web.
        $route = Route::getRoutes()->getByName('voiceflow.webhook');

        $this->assertNotNull($route, 'Webhook route must be named voiceflow.webhook');
        $this->assertContains('api', $route->middleware(), 'Webhook must live in the api middleware group');
        $this->assertNotContains('web', $route->middleware(), 'Webhook must NOT live in the web group (CSRF + session)');
    }

    public function test_voiceflow_webhook_authenticates_via_per_agent_secret_instead_of_csrf(): void
    {
        // End-to-end sanity: a cross-origin POST with the right per-agent
        // secret succeeds (no CSRF token in sight); without the secret it
        // 401s. That's the guard that replaces CSRF for this endpoint.
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $this->postJson(route('voiceflow.webhook', $agent), ['name' => 'A'], [
            'X-Webhook-Secret' => $agent->webhook_secret,
        ])->assertOk();

        $this->postJson(route('voiceflow.webhook', $agent), ['name' => 'A'], [
            'X-Webhook-Secret' => 'wrong',
        ])->assertStatus(401);
    }
}
