<?php

namespace App\Runtime\LLM;

use App\Runtime\Exceptions\Misconfigured;
use App\Runtime\Exceptions\UpstreamUnavailable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Gemini client (generateContent API).
 *
 * Translates the runtime's canonical (Anthropic-shaped) messages to
 * Gemini's contents/parts format and back. Gemini quirks handled here:
 *  - roles are user|model (not assistant)
 *  - tool calls are functionCall parts; results are functionResponse
 *    parts keyed by NAME, not id — we synthesize stable ids and keep an
 *    id→name map from the canonical history
 *  - the system prompt rides a separate systemInstruction field
 */
class GeminiClient implements LlmClient
{
    private ?string $apiKeyOverride = null;

    /**
     * A customer-supplied Google key. Clones rather than mutating: clients
     * are resolved from the container and shared, so setting the key in
     * place would leak one team's key into another team's request.
     */
    public function withApiKey(string $apiKey): static
    {
        $clone = clone $this;
        $clone->apiKeyOverride = $apiKey;

        return $clone;
    }

    /**
     * @param  string|list<array<string, mixed>>  $system  System prompt (blocks flattened to text; Gemini has no explicit cache control)
     * @param  list<array<string, mixed>>  $messages  Canonical messages
     * @param  list<array<string, mixed>>  $tools  Canonical tool specs
     */
    public function complete(string|array $system, array $messages, array $tools = [], ?string $model = null, ?int $maxTokens = null): CompletionResult
    {
        $apiKey = $this->apiKeyOverride ?? (string) config('runtime.llm.google.api_key');
        if ($apiKey === '') {
            throw new Misconfigured('GEMINI_API_KEY is not set — the Gemini tier cannot answer.');
        }

        $model = $model ?? (string) config('runtime.llm.google.model_default');

        $payload = [
            'systemInstruction' => ['parts' => [['text' => SystemPrompt::toText($system)]]],
            'contents' => $this->toGeminiContents($messages),
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens ?? (int) config('runtime.llm.google.max_tokens', config('runtime.llm.anthropic.max_tokens')),
            ],
        ];
        if ($tools !== []) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(fn (array $t) => [
                    'name' => $t['name'],
                    'description' => $t['description'],
                    'parameters' => $t['input_schema'],
                ], $tools),
            ]];
        }

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->timeout(60)
                ->retry(2, 300, function (Throwable $e): bool {
                    return $e instanceof RequestException
                        && in_array($e->response->status(), [429, 500, 502, 503], true);
                }, throw: false)
                ->post('/v1beta/models/'.rawurlencode($model).':generateContent', $payload);
        } catch (Throwable $e) {
            throw new UpstreamUnavailable('Gemini unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            $detail = (string) $response->json('error.message', $response->body());

            throw new UpstreamUnavailable('Gemini returned HTTP '.$response->status().': '.mb_substr($detail, 0, 300));
        }

        return $this->parse($response->json());
    }

    /**
     * Canonical → Gemini contents. functionResponse parts need the tool
     * NAME, so we resolve ids via the tool_use blocks earlier in history.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    protected function toGeminiContents(array $messages): array
    {
        // id → name map from every tool_use block in the conversation.
        $toolNames = [];
        foreach ($messages as $message) {
            foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
                if (($block['type'] ?? '') === 'tool_use') {
                    $toolNames[(string) $block['id']] = (string) $block['name'];
                }
            }
        }

        $contents = [];
        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            $role = ($message['role'] ?? '') === 'assistant' ? 'model' : 'user';

            if (is_string($content)) {
                $contents[] = ['role' => $role, 'parts' => [['text' => $content]]];

                continue;
            }

            $parts = [];
            foreach ((array) $content as $block) {
                $parts[] = match ($block['type'] ?? '') {
                    'text' => ['text' => (string) ($block['text'] ?? '')],
                    'tool_use' => ['functionCall' => [
                        'name' => (string) $block['name'],
                        'args' => (object) ($block['input'] ?? []),
                    ]],
                    'tool_result' => ['functionResponse' => [
                        'name' => $toolNames[(string) ($block['tool_use_id'] ?? '')] ?? 'unknown_tool',
                        'response' => [
                            'result' => (string) ($block['content'] ?? ''),
                            // canonical is_error survives the translation
                            'error' => (bool) ($block['is_error'] ?? false),
                        ],
                    ]],
                    default => null,
                };
            }
            $parts = array_values(array_filter($parts));
            if ($parts !== []) {
                $contents[] = ['role' => $role, 'parts' => $parts];
            }
        }

        return $contents;
    }

    /**
     * Gemini response → canonical CompletionResult. Gemini doesn't issue
     * tool-call ids, so we synthesize stable ones (name + position) —
     * the tool_result pairing round-trips by name anyway.
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function parse(?array $body): CompletionResult
    {
        $parts = (array) ($body['candidates'][0]['content']['parts'] ?? []);

        $text = '';
        $toolCalls = [];
        $blocks = [];
        $i = 0;
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
                $blocks[] = ['type' => 'text', 'text' => (string) $part['text']];
            }
            if (isset($part['functionCall'])) {
                $name = (string) ($part['functionCall']['name'] ?? '');
                $args = (array) ($part['functionCall']['args'] ?? []);
                // Newer API revisions include an id on functionCall — use it
                // when present, else synthesize a stable one.
                $id = (string) ($part['functionCall']['id'] ?? ('gem_'.$name.'_'.$i++));
                $toolCalls[] = new ToolCall(id: $id, name: $name, input: $args);
                $blocks[] = ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $args];
            }
        }

        $finish = (string) ($body['candidates'][0]['finishReason'] ?? 'STOP');

        return new CompletionResult(
            text: $text,
            toolCalls: $toolCalls,
            contentBlocks: $blocks,
            stopReason: match (true) {
                $toolCalls !== [] => 'tool_use',
                $finish === 'MAX_TOKENS' => 'max_tokens',
                default => 'end_turn',
            },
            inputTokens: (int) ($body['usageMetadata']['promptTokenCount'] ?? 0),
            outputTokens: (int) ($body['usageMetadata']['candidatesTokenCount'] ?? 0),
        );
    }

    protected function baseUrl(): string
    {
        $url = (string) config('runtime.llm.google.base_url', '');

        return $url !== '' ? $url : 'https://generativelanguage.googleapis.com';
    }
}
