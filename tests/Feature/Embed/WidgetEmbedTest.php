<?php

namespace Tests\Feature\Embed;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\Contracts\Runtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Embeddable-widget public surface: frame-ancestors CSP, the best-effort
 * origin check on launch/interact, widget JS theming, and active-only gating.
 *
 * The Runtime contract is faked so launch/interact never touch a real LLM —
 * canned traces are enough for the HTTP-level assertions here.
 */
class WidgetEmbedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind a fake engine returning canned traces — no network, no LLM.
        $this->app->instance(Runtime::class, new class implements Runtime
        {
            public function launch(Agent $agent, string $visitorId): array
            {
                return [['type' => 'text', 'payload' => ['message' => 'hi']]];
            }

            public function hasSession(Agent $agent, string $visitorId): bool
            {
                return false;
            }

            public function transcript(Agent $agent, string $visitorId): array
            {
                return [];
            }

            public function sendText(Agent $agent, string $visitorId, string $text): array
            {
                return [['type' => 'text', 'payload' => ['message' => 'hi']]];
            }

            public function streamText(Agent $agent, string $visitorId, string $text): \Generator
            {
                yield ['event' => 'done', 'data' => []];
            }

            public function endSession(Agent $agent, string $visitorId): void {}

            public function health(Agent $agent): array
            {
                return ['ok' => true, 'configured' => true];
            }
        });
    }

    public function test_chat_page_is_embeddable_anywhere_without_allowlist(): void
    {
        $agent = $this->makeAgent('active', []);

        $response = $this->get("/embed/{$agent->slug}")->assertOk();

        $this->assertStringContainsString(
            'frame-ancestors *',
            (string) $response->headers->get('Content-Security-Policy'),
        );
        $this->assertSame('ALLOWALL', $response->headers->get('X-Frame-Options'));
    }

    public function test_chat_page_restricts_frame_ancestors_with_allowlist(): void
    {
        $agent = $this->makeAgent('active', ['acme.com']);

        $response = $this->get("/embed/{$agent->slug}")->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://acme.com', $csp);
        $this->assertStringNotContainsString('frame-ancestors *', $csp);

        // With an allowlist the controller does NOT emit the permissive
        // ALLOWALL — enforcement is via frame-ancestors (X-Frame-Options
        // can't express multiple origins). The global SecurityHeaders
        // middleware backfills SAMEORIGIN when the header is absent.
        $this->assertNotSame('ALLOWALL', $response->headers->get('X-Frame-Options'));
    }

    public function test_launch_is_forbidden_from_a_disallowed_host(): void
    {
        $agent = $this->makeAgent('active', ['acme.com']);

        $this->postJson("/embed/{$agent->slug}/launch", ['host' => 'evil.com'])
            ->assertStatus(403);
    }

    public function test_launch_is_allowed_from_an_allowlisted_host(): void
    {
        $agent = $this->makeAgent('active', ['acme.com']);

        $this->postJson("/embed/{$agent->slug}/launch", ['host' => 'acme.com'])
            ->assertOk()
            ->assertJsonStructure(['visitor_id', 'agent_name', 'traces']);
    }

    public function test_interact_is_forbidden_from_a_disallowed_host(): void
    {
        $agent = $this->makeAgent('active', ['acme.com']);

        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-test',
            'message' => 'hello',
            'host' => 'evil.com',
        ])->assertStatus(403);
    }

    public function test_launch_with_empty_allowlist_accepts_any_host(): void
    {
        $agent = $this->makeAgent('active', []);

        $this->postJson("/embed/{$agent->slug}/launch", ['host' => 'whatever.example'])
            ->assertOk();
    }

    public function test_interact_with_empty_allowlist_is_not_forbidden(): void
    {
        $agent = $this->makeAgent('active', []);

        // Empty allowlist → origin check is skipped; this must not 403
        // (it reaches the engine and succeeds via the faked Runtime).
        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-test',
            'message' => 'hello',
            'host' => 'somewhere.example',
        ])->assertOk();
    }

    public function test_widget_js_carries_the_configured_accent_color(): void
    {
        $agent = $this->makeAgent('active', []);
        $agent->forceFill(['widget_config' => ['accent_color' => '#123456']])->save();

        $response = $this->get("/widget/{$agent->slug}.js")->assertOk();

        $this->assertSame(
            'application/javascript; charset=utf-8',
            $response->headers->get('Content-Type'),
        );
        $this->assertStringContainsString('#123456', $response->getContent());
    }

    public function test_inactive_agent_chat_and_widget_404(): void
    {
        $agent = $this->makeAgent('draft', []);

        $this->get("/embed/{$agent->slug}")->assertNotFound();
        $this->get("/widget/{$agent->slug}.js")->assertNotFound();
    }

    /**
     * @param  list<string>  $domains
     */
    private function makeAgent(string $status, array $domains): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['credit_balance' => 1000])->save();

        return Agent::factory()->for($team)->create([
            'status' => $status,
            'allowed_domains' => $domains,
        ]);
    }
}
