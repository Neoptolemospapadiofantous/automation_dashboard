<?php

namespace Tests\Feature\Runtime;

use App\Runtime\Exceptions\Misconfigured;
use App\Runtime\Exceptions\UpstreamUnavailable;
use App\Runtime\LLM\AnthropicClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['runtime.llm.anthropic.api_key' => 'sk-test']);
    }

    public function test_missing_key_throws_misconfigured(): void
    {
        config(['runtime.llm.anthropic.api_key' => '']);

        $this->expectException(Misconfigured::class);
        (new AnthropicClient)->complete('sys', [['role' => 'user', 'content' => 'hi']]);
    }

    public function test_plain_text_completion(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hello!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 12, 'output_tokens' => 3],
            ]),
        ]);

        $result = (new AnthropicClient)->complete('sys', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Hello!', $result->text);
        $this->assertFalse($result->wantsTools());
        $this->assertSame(12, $result->inputTokens);
        $this->assertSame(3, $result->outputTokens);

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('x-api-key', 'sk-test')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request['system'] === 'sys'
                && ! array_key_exists('tools', $request->data());
        });
    }

    public function test_tool_use_parsing(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Let me save that.'],
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'capture_lead', 'input' => ['name' => 'Bob']],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 30, 'output_tokens' => 20],
            ]),
        ]);

        $tools = [['name' => 'capture_lead', 'description' => 'd', 'input_schema' => ['type' => 'object']]];
        $result = (new AnthropicClient)->complete('sys', [['role' => 'user', 'content' => 'hi']], $tools);

        $this->assertTrue($result->wantsTools());
        $this->assertCount(1, $result->toolCalls);
        $this->assertSame('capture_lead', $result->toolCalls[0]->name);
        $this->assertSame('toolu_1', $result->toolCalls[0]->id);
        $this->assertSame(['name' => 'Bob'], $result->toolCalls[0]->input);
        // Raw blocks preserved for the assistant-message echo.
        $this->assertCount(2, $result->contentBlocks);

        Http::assertSent(fn ($request): bool => array_key_exists('tools', $request->data()));
    }

    public function test_api_error_throws_upstream_unavailable(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens required'],
            ], 400),
        ]);

        try {
            (new AnthropicClient)->complete('sys', [['role' => 'user', 'content' => 'hi']]);
            $this->fail('Expected UpstreamUnavailable');
        } catch (UpstreamUnavailable $e) {
            $this->assertStringContainsString('400', $e->getMessage());
            $this->assertStringContainsString('max_tokens required', $e->getMessage());
        }
    }
}
