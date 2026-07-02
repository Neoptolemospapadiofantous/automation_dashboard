<?php

namespace Tests\Wiring;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Category E (Wiring) — E4 routes ↔ controllers ↔ middleware ↔ frontend.
 *
 * Static pass over the route table plus named-route pins for every
 * route('...') name the Vue layer references through Ziggy — a renamed
 * or deleted backend route otherwise crashes the frontend at runtime
 * ("Ziggy error: route X is not in the route list").
 */
class RouteWiringTest extends TestCase
{
    /**
     * Route names referenced by vendor-published Jetstream Vue components
     * whose backing routes are intentionally not registered (the feature
     * is disabled, and the reference sits behind a feature-flag guard in
     * the component, so Ziggy never evaluates it at runtime).
     *
     * TODO: delete the stale Jetstream pages (Pages/API/*, the terms link
     * in Register.vue, the API-tokens link in AppLayout.vue) or enable the
     * features, then shrink this list to empty.
     *
     * @var list<string>
     */
    private const UNROUTED_FEATURE_FLAGGED = [
        'api-tokens.index',     // Jetstream API tokens feature disabled
        'api-tokens.store',     // Jetstream API tokens feature disabled
        'api-tokens.update',    // Jetstream API tokens feature disabled
        'api-tokens.destroy',   // Jetstream API tokens feature disabled
        'terms.show',           // Jetstream terms & privacy feature disabled
        'policy.show',          // Jetstream terms & privacy feature disabled
    ];

    /**
     * Operator routes registered only inside app()->environment('local')
     * in routes/web.php — absent here (testing) and in production by
     * design. Their sidebar links are guarded with hasRoute(), so Ziggy
     * never evaluates them where the route is missing.
     *
     * @var list<string>
     */
    private const LOCAL_ONLY_ROUTES = [
        'hermes.metrics',       // Hermes effectiveness KPIs (local ops page)
        'architecture.graph',   // Mermaid render of the architecture doc
    ];

    public function test_every_route_action_exists(): void
    {
        $failures = [];

        foreach (Route::getRoutes() as $route) {
            $controller = $route->getControllerClass();
            if ($controller === null) {
                continue; // Closure route — nothing to verify statically
            }

            if (! class_exists($controller)) {
                $failures[] = "{$route->uri()} → missing controller {$controller}";

                continue;
            }

            $method = $route->getActionMethod();
            if ($method === $controller) {
                $method = '__invoke';
            }

            if (! method_exists($controller, $method)) {
                $failures[] = "{$route->uri()} → missing {$controller}::{$method}()";
            }
        }

        $this->assertSame([], $failures, "Routes pointing at missing actions:\n".implode("\n", $failures));
    }

    public function test_every_route_middleware_resolves(): void
    {
        $router = $this->app['router'];
        $aliases = $router->getMiddleware();
        $groups = $router->getMiddlewareGroups();

        $failures = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                foreach ($this->flatten($middleware, $groups) as $entry) {
                    $name = Str::before($entry, ':');
                    $resolved = $aliases[$name] ?? $name;

                    if (! is_string($resolved)) {
                        continue; // Closure middleware
                    }

                    // "Class@method"-style entries resolve on the class part.
                    $class = Str::before($resolved, '@');

                    if (! class_exists($class)) {
                        $failures[] = "{$route->uri()} → middleware '{$entry}' resolves to missing class {$class}";
                    }
                }
            }
        }

        $this->assertSame([], $failures, "Unresolvable route middleware:\n".implode("\n", array_unique($failures)));
    }

    public function test_every_route_name_referenced_in_the_vue_frontend_exists(): void
    {
        $referenced = $this->routeNamesReferencedInJs();

        // Discovery-rot guard: the grep must keep finding real references.
        $this->assertContains('dashboard', $referenced);
        $this->assertContains('chat.launch', $referenced);
        $this->assertGreaterThan(40, count($referenced), 'resources/js route() discovery looks broken');

        $missing = [];
        foreach ($referenced as $name) {
            if (in_array($name, self::UNROUTED_FEATURE_FLAGGED, true)
                || in_array($name, self::LOCAL_ONLY_ROUTES, true)) {
                continue;
            }

            if (! Route::has($name)) {
                $missing[] = $name;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Route names referenced in resources/js but not registered (Ziggy will crash):\n".implode("\n", $missing)
        );
    }

    /**
     * Expand middleware group names into their terminal entries.
     *
     * @param  array<string, array<int, mixed>>  $groups
     * @return list<string>
     */
    private function flatten(mixed $middleware, array $groups, int $depth = 0): array
    {
        if ($middleware instanceof Closure || $depth > 4) {
            return [];
        }

        if (! is_string($middleware)) {
            return [];
        }

        if (isset($groups[$middleware])) {
            $entries = [];
            foreach ($groups[$middleware] as $inner) {
                $entries = array_merge($entries, $this->flatten($inner, $groups, $depth + 1));
            }

            return $entries;
        }

        return [$middleware];
    }

    /**
     * Every literal route('...') name in the Vue layer, at test runtime.
     *
     * @return list<string>
     */
    private function routeNamesReferencedInJs(): array
    {
        $names = [];

        foreach (File::allFiles(resource_path('js')) as $file) {
            if ($file->getExtension() !== 'vue') {
                continue;
            }

            preg_match_all("/\\broute\\(\\s*'([^']+)'/", $file->getContents(), $matches);
            foreach ($matches[1] as $name) {
                $names[$name] = true;
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }
}
