<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies CSRF is wired the way we expect for the SaaS:
 * - Web POST endpoints (Inertia forms) have ValidateCsrfToken in their
 *   middleware pipeline → real cross-origin POSTs without a token are
 *   rejected with 419.
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
}
