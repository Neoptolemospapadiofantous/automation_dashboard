<?php

namespace Tests\Contracts;

use App\Runtime\Exceptions\Misconfigured;
use App\Runtime\Exceptions\UpstreamUnavailable;
use App\Runtime\LLM\AnthropicClient;
use App\Runtime\LLM\GeminiClient;
use App\Runtime\LLM\LlmClient;
use App\Runtime\LLM\OpenAiClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The LlmClient CONTRACT: all three provider clients must behave
 * identically through the canonical (Anthropic-shaped) message format —
 * same CompletionResult semantics in, same canonical contentBlocks out,
 * regardless of how each provider's wire format differs.
 *
 * This is the modern replacement for the obsolete legacy-engine parity
 * gate (Category C of PROJECT_ASSURANCE_STRATEGY.md): the parity that
 * matters now is BETWEEN PROVIDERS, so an agent switched from Claude to
 * GPT to Gemini mid-conversation keeps a replayable history and the
 * FlowExecutor never needs to know which provider answered.
 */
class LlmClientContractTest extends TestCase
{
    private const SYSTEM_PROMPT = 'You are the contract-test assistant.';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'runtime.llm.anthropic.api_key' => 'sk-ant-contract',
            'runtime.llm.openai.api_key' => 'sk-oai-contract',
            'runtime.llm.google.api_key' => 'sk-gem-contract',
        ]);
    }

    /**
     * One entry per client: how to fake its wire responses and how to dig
     * provider-specific structures back out of the outbound payload.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function clients(): array
    {
        return [
            'anthropic' => [[
                'class' => AnthropicClient::class,
                'fake' => 'api.anthropic.com/*',
                'env' => 'ANTHROPIC_API_KEY',
                'config' => 'runtime.llm.anthropic.api_key',
                'wireText' => fn (string $text, int $in, int $out): array => [
                    'content' => [['type' => 'text', 'text' => $text]],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
                ],
                'wireToolCall' => fn (string $text, string $tool, array $input, int $in, int $out): array => [
                    'content' => [
                        ['type' => 'text', 'text' => $text],
                        ['type' => 'tool_use', 'id' => 'toolu_contract_1', 'name' => $tool, 'input' => $input],
                    ],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
                ],
                'wireEmpty' => fn (): array => [
                    'content' => [],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 0],
                ],
                'toolSpecFromPayload' => fn (array $payload): array => [
                    'name' => $payload['tools'][0]['name'] ?? null,
                    'description' => $payload['tools'][0]['description'] ?? null,
                    'parameters' => $payload['tools'][0]['input_schema'] ?? null,
                ],
            ]],

            'openai' => [[
                'class' => OpenAiClient::class,
                'fake' => 'api.openai.com/v1/chat/completions',
                'env' => 'OPENAI_API_KEY',
                'config' => 'runtime.llm.openai.api_key',
                'wireText' => fn (string $text, int $in, int $out): array => [
                    'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out],
                ],
                'wireToolCall' => fn (string $text, string $tool, array $input, int $in, int $out): array => [
                    'choices' => [[
                        'message' => [
                            'content' => $text,
                            'tool_calls' => [[
                                'id' => 'call_contract_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => $tool,
                                    'arguments' => (string) json_encode($input, JSON_UNESCAPED_SLASHES),
                                ],
                            ]],
                        ],
                        'finish_reason' => 'tool_calls',
                    ]],
                    'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out],
                ],
                'wireEmpty' => fn (): array => [
                    'choices' => [['message' => ['content' => null], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 0],
                ],
                'toolSpecFromPayload' => fn (array $payload): array => [
                    'name' => $payload['tools'][0]['function']['name'] ?? null,
                    'description' => $payload['tools'][0]['function']['description'] ?? null,
                    'parameters' => $payload['tools'][0]['function']['parameters'] ?? null,
                ],
            ]],

            'gemini' => [[
                'class' => GeminiClient::class,
                'fake' => 'generativelanguage.googleapis.com/*',
                'env' => 'GEMINI_API_KEY',
                'config' => 'runtime.llm.google.api_key',
                'wireText' => fn (string $text, int $in, int $out): array => [
                    'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]]]],
                    'usageMetadata' => ['promptTokenCount' => $in, 'candidatesTokenCount' => $out],
                ],
                'wireToolCall' => fn (string $text, string $tool, array $input, int $in, int $out): array => [
                    'candidates' => [['content' => ['role' => 'model', 'parts' => [
                        ['text' => $text],
                        ['functionCall' => ['name' => $tool, 'args' => $input]],
                    ]]]],
                    'usageMetadata' => ['promptTokenCount' => $in, 'candidatesTokenCount' => $out],
                ],
                'wireEmpty' => fn (): array => [
                    'candidates' => [['content' => ['role' => 'model', 'parts' => []]]],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 0],
                ],
                'toolSpecFromPayload' => fn (array $payload): array => [
                    'name' => $payload['tools'][0]['functionDeclarations'][0]['name'] ?? null,
                    'description' => $payload['tools'][0]['functionDeclarations'][0]['description'] ?? null,
                    'parameters' => $payload['tools'][0]['functionDeclarations'][0]['parameters'] ?? null,
                ],
            ]],
        ];
    }

    // ── 1. plain text ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $p
     */
    #[DataProvider('clients')]
    public function test_plain_text_completion_maps_to_the_canonical_result(array $p): void
    {
        Http::fake([$p['fake'] => Http::response(($p['wireText'])('Hello from the model.', 37, 11))]);

        $result = $this->client($p)->complete(self::SYSTEM_PROMPT, [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Hello from the model.', $result->text);
        $this->assertFalse($result->wantsTools());
        $this->assertSame('end_turn', $result->stopReason);
        $this->assertSame([], $result->toolCalls);
        $this->assertEquals([['type' => 'text', 'text' => 'Hello from the model.']], $result->contentBlocks);
        $this->assertSame(37, $result->inputTokens);
        $this->assertSame(11, $result->outputTokens);
    }

    // ── 2. tool call ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $p
     */
    #[DataProvider('clients')]
    public function test_tool_call_completion_yields_one_canonical_tool_use(array $p): void
    {
        $input = ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'score' => 75];
        Http::fake([$p['fake'] => Http::response(($p['wireToolCall'])('On it…', 'capture_lead', $input, 41, 23))]);

        $result = $this->client($p)->complete(self::SYSTEM_PROMPT, [['role' => 'user', 'content' => 'save me']]);

        $this->assertTrue($result->wantsTools());
        $this->assertSame('tool_use', $result->stopReason);
        $this->assertCount(1, $result->toolCalls);

        $call = $result->toolCalls[0];
        $this->assertSame('capture_lead', $call->name);
        $this->assertSame($input, $call->input); // decoded argument bag, not a JSON string
        $this->assertNotSame('', $call->id);

        // contentBlocks must carry a canonical tool_use block with the SAME
        // id as the ToolCall — the FlowExecutor appends these blocks back as
        // the assistant message and pairs tool_results by that id.
        $toolBlocks = array_values(array_filter($result->contentBlocks, fn (array $b) => ($b['type'] ?? '') === 'tool_use'));
        $this->assertCount(1, $toolBlocks);
        $this->assertSame($call->id, $toolBlocks[0]['id']);
        $this->assertSame('capture_lead', $toolBlocks[0]['name']);
        $this->assertEquals($input, $toolBlocks[0]['input']);

        $this->assertSame('On it…', $result->text);
        $this->assertSame(41, $result->inputTokens);
        $this->assertSame(23, $result->outputTokens);
    }

    // ── 3. round-trip translation of canonical history ───────────────────────

    public function test_anthropic_round_trip_passes_canonical_history_verbatim(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        app(AnthropicClient::class)->complete(self::SYSTEM_PROMPT, $this->canonicalHistory());

        $payload = $this->recordedPayload();

        // Canonical IS the Anthropic wire format: verbatim pass-through.
        $this->assertSame(self::SYSTEM_PROMPT, $payload['system']);
        $this->assertEquals($this->canonicalHistory(), $payload['messages']);
    }

    public function test_openai_round_trip_translates_canonical_history(): void
    {
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])]);

        app(OpenAiClient::class)->complete(self::SYSTEM_PROMPT, $this->canonicalHistory());

        $messages = $this->recordedPayload()['messages'];

        // System prompt becomes the FIRST message.
        $this->assertEquals(['role' => 'system', 'content' => self::SYSTEM_PROMPT], $messages[0]);
        $this->assertEquals(['role' => 'user', 'content' => 'What plans do you offer?'], $messages[1]);

        // Canonical tool_use → assistant.tool_calls with JSON-STRING arguments.
        $this->assertSame('assistant', $messages[2]['role']);
        $this->assertSame('Let me check that for you.', $messages[2]['content']);
        $this->assertCount(1, $messages[2]['tool_calls']);
        $toolCall = $messages[2]['tool_calls'][0];
        $this->assertSame('toolu_rt_1', $toolCall['id']);
        $this->assertSame('function', $toolCall['type']);
        $this->assertSame('query_kb', $toolCall['function']['name']);
        $this->assertIsString($toolCall['function']['arguments']);
        $this->assertEquals(['question' => 'available plans'], json_decode($toolCall['function']['arguments'], true));

        // Canonical tool_result → role:tool message carrying tool_call_id.
        $this->assertEquals([
            'role' => 'tool',
            'tool_call_id' => 'toolu_rt_1',
            'content' => 'Starter ($99/mo) and Pro ($299/mo).',
        ], $messages[3]);

        $this->assertEquals(['role' => 'assistant', 'content' => 'We offer Starter and Pro plans.'], $messages[4]);
        $this->assertCount(5, $messages);
    }

    public function test_gemini_round_trip_translates_canonical_history(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'ok']]]]],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ])]);

        app(GeminiClient::class)->complete(self::SYSTEM_PROMPT, $this->canonicalHistory());

        $payload = $this->recordedPayload();

        // System prompt rides the separate systemInstruction field.
        $this->assertSame(self::SYSTEM_PROMPT, $payload['systemInstruction']['parts'][0]['text']);

        $contents = $payload['contents'];
        $this->assertCount(4, $contents);

        // Gemini only knows user|model — no 'assistant' role may leak out.
        foreach ($contents as $content) {
            $this->assertContains($content['role'], ['user', 'model']);
        }

        $this->assertEquals(['role' => 'user', 'parts' => [['text' => 'What plans do you offer?']]], $contents[0]);

        // Canonical tool_use → functionCall part on a model turn.
        $this->assertSame('model', $contents[1]['role']);
        $this->assertEquals(['text' => 'Let me check that for you.'], $contents[1]['parts'][0]);
        $this->assertSame('query_kb', $contents[1]['parts'][1]['functionCall']['name']);
        // args is cast to (object) so empty inputs serialize as {} not [] —
        // Http::recorded() hands back the original payload, hence the cast.
        $this->assertEquals(['question' => 'available plans'], (array) $contents[1]['parts'][1]['functionCall']['args']);

        // Canonical tool_result → functionResponse keyed by the tool NAME,
        // resolved from the tool_use id earlier in the history.
        $this->assertSame('user', $contents[2]['role']);
        $this->assertEquals([
            'functionResponse' => [
                'name' => 'query_kb',
                // canonical is_error survives the translation (contract
                // finding #3, fixed 2026-06-12)
                'response' => ['result' => 'Starter ($99/mo) and Pro ($299/mo).', 'error' => false],
            ],
        ], $contents[2]['parts'][0]);

        $this->assertEquals(['role' => 'model', 'parts' => [['text' => 'We offer Starter and Pro plans.']]], $contents[3]);
    }

    // ── 4. tool spec translation ─────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $p
     */
    #[DataProvider('clients')]
    public function test_canonical_tool_specs_land_in_the_provider_payload(array $p): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Full name'],
                'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            ],
            'required' => ['name'],
        ];
        $spec = ['name' => 'capture_lead', 'description' => 'Save the visitor as a lead.', 'input_schema' => $schema];

        Http::fake([$p['fake'] => Http::response(($p['wireText'])('ok', 1, 1))]);

        $this->client($p)->complete(self::SYSTEM_PROMPT, [['role' => 'user', 'content' => 'hi']], [$spec]);

        $landed = ($p['toolSpecFromPayload'])($this->recordedPayload());

        $this->assertSame('capture_lead', $landed['name']);
        $this->assertSame('Save the visitor as a lead.', $landed['description']);
        // The SAME JSON schema object must land wherever the provider keeps it.
        $this->assertEquals($schema, $landed['parameters']);
    }

    // ── 5. failure normalization ─────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $p
     */
    #[DataProvider('clients')]
    public function test_missing_api_key_throws_misconfigured_naming_the_env_var(array $p): void
    {
        config([$p['config'] => '']);
        Http::fake(); // nothing may go out

        $this->expectException(Misconfigured::class);
        $this->expectExceptionMessage($p['env']);

        $this->client($p)->complete(self::SYSTEM_PROMPT, [['role' => 'user', 'content' => 'hi']]);

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $p
     */
    #[DataProvider('clients')]
    public function test_provider_http_500_maps_to_upstream_unavailable(array $p): void
    {
        Http::fake([$p['fake'] => Http::response(['error' => ['message' => 'kaboom']], 500)]);

        $this->expectException(UpstreamUnavailable::class);
        $this->expectExceptionMessage('500');

        $this->client($p)->complete(self::SYSTEM_PROMPT, [['role' => 'user', 'content' => 'hi']]);
    }

    // ── 6. degenerate responses ──────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $p
     */
    #[DataProvider('clients')]
    public function test_empty_response_yields_empty_text_and_end_turn_without_crashing(array $p): void
    {
        Http::fake([$p['fake'] => Http::response(($p['wireEmpty'])())]);

        $result = $this->client($p)->complete(self::SYSTEM_PROMPT, [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('', $result->text);
        $this->assertFalse($result->wantsTools());
        $this->assertSame('end_turn', $result->stopReason);
        $this->assertSame([], $result->toolCalls);
        $this->assertSame([], $result->contentBlocks);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $p
     */
    private function client(array $p): LlmClient
    {
        return app($p['class']);
    }

    /**
     * Canonical history covering every block type the runtime produces:
     * user text → assistant text+tool_use → user tool_result → assistant text.
     *
     * @return list<array<string, mixed>>
     */
    private function canonicalHistory(): array
    {
        return [
            ['role' => 'user', 'content' => 'What plans do you offer?'],
            ['role' => 'assistant', 'content' => [
                ['type' => 'text', 'text' => 'Let me check that for you.'],
                ['type' => 'tool_use', 'id' => 'toolu_rt_1', 'name' => 'query_kb', 'input' => ['question' => 'available plans']],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'toolu_rt_1', 'content' => 'Starter ($99/mo) and Pro ($299/mo).', 'is_error' => false],
            ]],
            ['role' => 'assistant', 'content' => 'We offer Starter and Pro plans.'],
        ];
    }

    /**
     * The single outbound request body captured by Http::fake().
     *
     * @return array<string, mixed>
     */
    private function recordedPayload(): array
    {
        $recorded = Http::recorded();
        $this->assertCount(1, $recorded);

        return $recorded[0][0]->data();
    }
}
