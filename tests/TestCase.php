<?php

namespace Tests;

use App\Http\Middleware\RequireAgent;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RequireAgent is meant for the production redirect-to-wizard flow.
        // In tests almost every authenticated user is created without going
        // through onboarding (factories + database fixtures), so the global
        // middleware would short-circuit nearly every Feature test before
        // it could assert anything. Tests that explicitly verify wizard
        // routing call $this->withMiddleware(RequireAgent::class) to re-enable.
        $this->withoutMiddleware(RequireAgent::class);

        // Stub the Vite manifest so view-rendering tests don't 500 when the
        // frontend hasn't been built (CI runs PHP tests without a pnpm build).
        // Tests assert Inertia props, not asset tags.
        $this->withoutVite();
    }
}
