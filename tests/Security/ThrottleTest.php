<?php

namespace Tests\Security;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Category F — rate limiting.
 *
 * Asserts a throttle middleware is REGISTERED on every abuse-sensitive
 * route instead of firing 60+ real requests per route (slow, and the
 * chat routes would need LLM fakes). Registration is the invariant that
 * regresses when someone re-declares a route and forgets the middleware.
 */
class ThrottleTest extends TestCase
{
    /**
     * Route name → why it must be throttled.
     *
     * @return array<string, array{0: string}>
     */
    public static function throttledRoutes(): array
    {
        return [
            'embed launch (public, unauthenticated)' => ['embed.launch'],
            'embed interact (public, debits credits)' => ['embed.interact'],
            'chat interact (LLM turn, debits credits)' => ['chat.interact'],
            'knowledge query (LLM synthesis, debits credits)' => ['knowledge.query'],
            'public stats (unauthenticated API)' => ['public.stats'],
        ];
    }

    #[DataProvider('throttledRoutes')]
    public function test_route_has_throttle_middleware(string $routeName): void
    {
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route {$routeName} is not registered.");

        $throttles = array_filter(
            $route->gatherMiddleware(),
            fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'),
        );

        $this->assertNotEmpty(
            $throttles,
            "Route {$routeName} has no throttle:* middleware — it can be hammered without limit.",
        );
    }
}
