<?php

namespace Tests\Feature\Runtime;

use App\Runtime\Exceptions\Misconfigured;
use App\Runtime\Exceptions\UpstreamUnavailable;
use App\Runtime\LLM\AnthropicClient;
use App\Runtime\LLM\BridgeClient;
use App\Runtime\LLM\LlmRouter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BridgeClient — Anthropic tiers over claude-bridge (subscription auth,
 * no metered API). Covers the prompt/parse contract both ways: canonical
 * messages+tools flatten into the bridge's single-prompt request, and a
 * strict-JSON reply parses back into a canonical tool_use block.
 */
class BridgeClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'runtime.llm.bridge.enabled' => true,
            'runtime.llm.bridge.url' => 'http://bridge.test:8765',
            'runtime.llm.bridge.token' => 'secret-token',
        ]);
    }

    public function test_plain_text_reply_parses_as_end_turn(): void
    {
        Http::fake(['bridge.test:8765/complete' => Http::response(['text' => 'Hello from the bridge.'])]);

        $result = app(BridgeClient::class)->complete('You are helpful.', [
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertSame('Hello from the bridge.', $result->text);
        $this->assertSame('end_turn', $result->stopReason);
        $this->assertFalse($result->wantsTools());

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://bridge.test:8765/complete'
                && $request->hasHeader('X-Service-Token', 'secret-token')
                && str_contains((string) $request['user'], 'USER: hi')
                && $request['model'] !== '';
        });
    }

    public function test_json_tool_reply_parses_as_canonical_tool_use(): void
    {
        Http::fake(['bridge.test:8765/complete' => Http::response([
            'text' => '{"tool": "capture_lead", "input": {"email": "a@b.com"}}',
        ])]);

        $tools = [[
            'name' => 'capture_lead',
            'description' => 'Capture a lead',
            'input_schema' => ['type' => 'object', 'properties' => ['email' => ['type' => 'string']]],
        ]];

        $result = app(BridgeClient::class)->complete('sys', [
            ['role' => 'user', 'content' => 'my email is a@b.com'],
        ], $tools);

        $this->assertTrue($result->wantsTools());
        $this->assertSame('capture_lead', $result->toolCalls[0]->name);
        $this->assertSame(['email' => 'a@b.com'], $result->toolCalls[0]->input);
        $this->assertSame('tool_use', $result->contentBlocks[0]['type']);

        // The tool protocol travels in the system prompt.
        Http::assertSent(fn (Request $r): bool => str_contains((string) $r['system'], '"capture_lead"'));
    }

    public function test_tool_history_blocks_render_into_the_transcript(): void
    {
        Http::fake(['bridge.test:8765/complete' => Http::response(['text' => 'Done.'])]);

        app(BridgeClient::class)->complete('sys', [
            ['role' => 'user', 'content' => 'capture me'],
            ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'id' => 't1', 'name' => 'capture_lead', 'input' => ['email' => 'a@b.com']],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'lead saved'],
            ]],
        ]);

        Http::assertSent(function (Request $r): bool {
            $u = (string) $r['user'];

            return str_contains($u, 'ASSISTANT [called tool capture_lead')
                && str_contains($u, 'TOOL RESULT [ok]: lead saved');
        });
    }

    public function test_bridge_error_throws_upstream_unavailable(): void
    {
        Http::fake(['bridge.test:8765/complete' => Http::response(['error' => 'busy'], 502)]);

        $this->expectException(UpstreamUnavailable::class);
        app(BridgeClient::class)->complete('sys', [['role' => 'user', 'content' => 'hi']]);
    }

    public function test_disabled_bridge_throws_misconfigured(): void
    {
        config(['runtime.llm.bridge.enabled' => false]);

        $this->expectException(Misconfigured::class);
        app(BridgeClient::class)->complete('sys', [['role' => 'user', 'content' => 'hi']]);
    }

    public function test_router_routes_anthropic_through_bridge_when_enabled(): void
    {
        $router = app(LlmRouter::class);
        $this->assertInstanceOf(BridgeClient::class, $router->clientFor('anthropic'));

        config(['runtime.llm.bridge.enabled' => false]);
        $this->assertInstanceOf(AnthropicClient::class, $router->clientFor('anthropic'));
    }

    public function test_provider_available_counts_a_configured_bridge(): void
    {
        config(['runtime.llm.anthropic.api_key' => '']);
        $this->assertTrue(LlmRouter::providerAvailable('anthropic'));

        config(['runtime.llm.bridge.enabled' => false]);
        $this->assertFalse(LlmRouter::providerAvailable('anthropic'));
    }
}
