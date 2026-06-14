<?php

namespace Tests\Wiring;

use App\Runtime\AgentRuntime;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Knowledge\KnowledgeBase;
use App\Runtime\Tools\ToolRegistry;
use Tests\TestCase;
use Throwable;

/**
 * Category E (Wiring) — E1 container resolution.
 *
 * Laravel's container is stringly-typed and resolved at runtime; a broken
 * binding is a production incident unless a test forces every abstract
 * through app()->make() on every commit.
 */
class ContainerTest extends TestCase
{
    /**
     * Abstracts that legitimately cannot resolve in the test container:
     * either they require runtime parameters, or they're framework
     * bindings for optional packages/extensions this app doesn't ship.
     * Every entry needs a justification — app-owned bindings never
     * belong here.
     *
     * @var list<string>
     */
    private const SKIP = [
        // Fortify response objects take a runtime `string $status` param;
        // Fortify constructs them with makeWith() inside its actions.
        'Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse',
        'Laravel\Fortify\Contracts\FailedPasswordResetResponse',
        'Laravel\Fortify\Contracts\PasswordResetResponse',
        'Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse',
        // Framework bindings for packages/extensions intentionally absent:
        'Psr\Http\Message\ServerRequestInterface',  // needs symfony/psr-http-message-bridge
        'Psr\Http\Message\ResponseInterface',       // needs symfony/psr-http-message-bridge
        'cache.psr6',                               // needs symfony/cache adapter
        'Illuminate\Bus\DynamoBatchRepository',     // needs AWS DynamoDB config ("region")
        'redis.connection',                         // needs ext-redis (phpredis)
    ];

    public function test_every_registered_binding_resolves(): void
    {
        $failures = [];

        foreach (array_keys($this->app->getBindings()) as $abstract) {
            if (in_array($abstract, self::SKIP, true)) {
                continue;
            }

            try {
                $this->app->make($abstract);
            } catch (Throwable $e) {
                $failures[] = sprintf('%s → %s: %s', $abstract, $e::class, $e->getMessage());
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Container bindings that fail to resolve:\n".implode("\n", $failures)
        );
    }

    public function test_runtime_contract_resolves_to_the_native_engine_as_a_singleton(): void
    {
        $runtime = $this->app->make(Runtime::class);

        $this->assertInstanceOf(AgentRuntime::class, $runtime);
        $this->assertSame($runtime, $this->app->make(Runtime::class), 'Runtime must be a singleton');
    }

    public function test_knowledge_store_contract_resolves_to_the_knowledge_base_as_a_singleton(): void
    {
        $store = $this->app->make(KnowledgeStore::class);

        $this->assertInstanceOf(KnowledgeBase::class, $store);
        $this->assertSame($store, $this->app->make(KnowledgeStore::class), 'KnowledgeStore must be a singleton');
    }

    public function test_tool_registry_has_all_five_builtin_tools(): void
    {
        $expected = ['capture_lead', 'query_kb', 'set_variable', 'request_handoff', 'end_session'];

        $registry = $this->app->make(ToolRegistry::class);
        $specs = $registry->specs($expected);

        // specs() silently skips unregistered names — equality on the name
        // column therefore proves every one of the 5 tools is registered.
        $this->assertSame($expected, array_column($specs, 'name'));

        foreach ($specs as $spec) {
            $this->assertNotSame('', (string) $spec['description'], "{$spec['name']} must describe itself to the model");
            $this->assertIsArray($spec['input_schema'], "{$spec['name']} must expose an input schema");
        }

        $this->assertSame($registry, $this->app->make(ToolRegistry::class), 'ToolRegistry must be a singleton');
    }
}
