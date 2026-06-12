<?php

namespace Tests\Integrity;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestResponse;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Category D (Integrity) — route smoke.
 *
 * As an authenticated user with a team + active native agent, every GET
 * route without required parameters must respond below 500. Redirects,
 * 4xx validation/authorization responses are acceptable smoke outcomes;
 * a 500 means a page is broken for everyone.
 */
class RouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes documented as broken — excluded so the suite is green while
     * the fix ships.
     *
     * TODO: GET /passkeys/confirm/options 500s because config/fortify.php
     * enables Features::passkeys() but App\Models\User does not implement
     * Laravel\Passkeys\Contracts\PasskeyUser (the controller throws
     * "User model must implement the PasskeyUser contract"). Either add
     * the contract + HasPasskeys trait to User or disable the feature,
     * then delete this exclusion.
     *
     * @var list<string>
     */
    /** Routes excluded with a reason; empty = everything must be healthy. */
    private const KNOWN_BROKEN = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'runtime.llm.anthropic.api_key' => 'sk-anthropic-test',
            'runtime.embeddings.openai_api_key' => 'sk-openai-test',
            'runtime.embeddings.dimensions' => 4,
        ]);

        // Nothing in a GET smoke pass should reach a provider, but any
        // accidental call must hit a fake, not the network.
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'smoke']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]),
            'api.openai.com/*' => Http::response(['data' => [['index' => 0, 'embedding' => [0.5, 0.5, 0.5, 0.5]]]]),
            'generativelanguage.googleapis.com/*' => Http::response([]),
        ]);
    }

    public function test_every_parameterless_get_route_responds_below_500(): void
    {
        $user = $this->userWithNativeAgent();

        $failures = [];
        $hit = 0;

        foreach (Route::getRoutes() as $route) {
            if (! $this->isSmokeable($route)) {
                continue;
            }

            $hit++;
            $uri = '/'.ltrim($route->uri(), '/');

            /** @var TestResponse<Response> $response */
            $response = $this->actingAs($user)->get($uri);

            if ($response->getStatusCode() >= 500) {
                $failures[] = sprintf('GET %s → %d', $uri, $response->getStatusCode());
            }
        }

        $this->assertGreaterThan(10, $hit, 'Route smoke discovery looks broken — almost no GET routes found');
        $this->assertSame([], $failures, "GET routes returning 5xx:\n".implode("\n", $failures));
    }

    public function test_core_dashboard_pages_render(): void
    {
        $user = $this->userWithNativeAgent();

        foreach ([
            'dashboard', 'agents.index', 'leads.index', 'conversations.index',
            'chat.index', 'knowledge.index', 'billing.index', 'install.index',
        ] as $name) {
            $this->actingAs($user)->get(route($name))->assertOk();
        }
    }

    private function isSmokeable(RoutingRoute $route): bool
    {
        if (! in_array('GET', $route->methods(), true)) {
            return false;
        }

        if (in_array($route->uri(), self::KNOWN_BROKEN, true)) {
            return false;
        }

        // Any route parameter (required or optional) is out of scope for a
        // blind smoke pass — parameterized pages get dedicated feature tests.
        return ! str_contains($route->uri(), '{');
    }

    private function userWithNativeAgent(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'runtime_mode' => 'native',
            'status' => 'active',
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id, 'credit_balance' => 100])->save();

        return $user->fresh();
    }
}
