<?php

namespace App\Runtime\LLM;

use App\Runtime\Exceptions\Misconfigured;
use App\Runtime\Exceptions\UpstreamUnavailable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OpenAI Chat Completions client (the "ChatGPT" tier).
 *
 * Translates the runtime's canonical (Anthropic-shaped) messages to
 * OpenAI's wire format and back — see LlmClient for why canonical
 * exists. Failures normalize into the Runtime exception hierarchy.
 */
class OpenAiClient implements LlmClient
{
    /**
     * @param  string|list<array<string, mixed>>  $system  System prompt (blocks flattened to text; OpenAI caches implicitly server-side)
     * @param  list<array<string, mixed>>  $messages  Canonical messages
     * @param  list<array<string, mixed>>  $tools  Canonical tool specs
     */
    /** Per-request key override (bring-your-own-key); null = platform key. */
    protected ?string $apiKeyOverride = null;

    /**
     * A clone of this client bound to a caller-supplied API key
     * (bring-your-own-key). Clients are stateless singletons, so cloning is
     * the safe way to scope a key to one turn without leaking it into the
     * container for every other tenant's request.
     */
    public function withApiKey(string $apiKey): static
    {
        $clone = clone $this;
        $clone->apiKeyOverride = $apiKey;

        return $clone;
    }

    public function complete(string|array $system, array $messages, array $tools = [], ?string $model = null, ?int $maxTokens = null): CompletionResult
    {
        $apiKey = $this->apiKeyOverride ?? (string) config('runtime.llm.openai.api_key');
        if ($apiKey === '') {
            throw new Misconfigured('OPENAI_API_KEY is not set — Flowstack Core cannot answer.');
        }

        $payload = [
            'model' => $model ?? (string) config('runtime.llm.openai.model_default'),
            'max_completion_tokens' => $maxTokens ?? (int) config('runtime.llm.openai.max_tokens', config('runtime.llm.anthropic.max_tokens')),
            'messages' => $this->toOpenAiMessages($system, $messages),
        ];
        // GPT-5-family models are reasoning models: without this, internal
        // reasoning can consume the ENTIRE max_completion_tokens budget and
        // return an empty reply (live-verified with gpt-5-nano at 1024).
        // Minimal effort suits chat turns; guarded by model name so the
        // local Ollama tier and non-reasoning models never see the param.
        if (str_starts_with((string) $payload['model'], 'gpt-5')) {
            $payload['reasoning_effort'] = (string) config('runtime.llm.openai.reasoning_effort', 'minimal');
        }
        if ($tools !== []) {
            $payload['tools'] = array_map(fn (array $t) => [
                'type' => 'function',
                'function' => [
                    'name' => $t['name'],
                    'description' => $t['description'],
                    'parameters' => $t['input_schema'],
                ],
            ], $tools);
        }

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->withToken($apiKey)
                ->timeout(60)
                ->retry(2, 300, function (Throwable $e): bool {
                    return $e instanceof RequestException
                        && in_array($e->response->status(), [429, 500, 502, 503], true);
                }, throw: false)
                ->post('/v1/chat/completions', $payload);
        } catch (Throwable $e) {
            throw new UpstreamUnavailable('OpenAI unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            $detail = (string) $response->json('error.message', $response->body());

            throw new UpstreamUnavailable('OpenAI returned HTTP '.$response->status().': '.mb_substr($detail, 0, 300));
        }

        return $this->parse($response->json());
    }

    /**
     * Canonical → OpenAI messages. tool_use blocks become assistant
     * tool_calls; tool_result blocks become role:tool messages.
     *
     * @param  string|list<array<string, mixed>>  $system
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    protected function toOpenAiMessages(string|array $system, array $messages): array
    {
        $out = [['role' => 'system', 'content' => SystemPrompt::toText($system)]];

        foreach ($messages as $message) {
            $content = $message['content'] ?? '';

            if (is_string($content)) {
                $out[] = ['role' => (string) $message['role'], 'content' => $content];

                continue;
            }

            if (($message['role'] ?? '') === 'assistant') {
                $text = '';
                $toolCalls = [];
                foreach ((array) $content as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $text .= (string) ($block['text'] ?? '');
                    }
                    if (($block['type'] ?? '') === 'tool_use') {
                        $toolCalls[] = [
                            'id' => (string) $block['id'],
                            'type' => 'function',
                            'function' => [
                                'name' => (string) $block['name'],
                                'arguments' => (string) json_encode($block['input'] ?? [], JSON_UNESCAPED_SLASHES),
                            ],
                        ];
                    }
                }
                // Content stays a string ('' when the turn was tool-calls
                // only). OpenAI accepts null alongside tool_calls, but
                // stricter OpenAI-compatible backends (e.g. Ollama) reject it
                // with "invalid message content type: <nil>".
                $entry = ['role' => 'assistant', 'content' => $text];
                if ($toolCalls !== []) {
                    $entry['tool_calls'] = $toolCalls;
                }
                $out[] = $entry;

                continue;
            }

            // user message carrying blocks: tool_result → role:tool entries;
            // text blocks → a user message (canonical allows both shapes —
            // dropping them silently was a history-replay divergence caught
            // by the contract suite).
            $userText = '';
            foreach ((array) $content as $block) {
                if (($block['type'] ?? '') === 'tool_result') {
                    $out[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) $block['tool_use_id'],
                        // is_error preserved as a structured prefix — OpenAI's
                        // role:tool has no error flag of its own.
                        'content' => (($block['is_error'] ?? false) ? '[tool error] ' : '').(string) $block['content'],
                    ];
                }
                if (($block['type'] ?? '') === 'text') {
                    $userText .= (string) ($block['text'] ?? '');
                }
            }
            if ($userText !== '') {
                $out[] = ['role' => 'user', 'content' => $userText];
            }
        }

        return $out;
    }

    /**
     * OpenAI response → canonical CompletionResult.
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function parse(?array $body): CompletionResult
    {
        $message = (array) ($body['choices'][0]['message'] ?? []);
        $finish = (string) ($body['choices'][0]['finish_reason'] ?? 'stop');

        $text = (string) ($message['content'] ?? '');
        $blocks = [];
        if ($text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        }

        $toolCalls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $input = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            $toolCalls[] = new ToolCall(
                id: (string) ($call['id'] ?? ''),
                name: (string) ($call['function']['name'] ?? ''),
                input: is_array($input) ? $input : [],
            );
            $blocks[] = [
                'type' => 'tool_use',
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($call['function']['name'] ?? ''),
                'input' => is_array($input) ? $input : [],
            ];
        }

        return new CompletionResult(
            text: $text,
            toolCalls: $toolCalls,
            contentBlocks: $blocks,
            stopReason: match ($finish) {
                'tool_calls' => 'tool_use',
                'length' => 'max_tokens',
                default => 'end_turn',
            },
            inputTokens: (int) ($body['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($body['usage']['completion_tokens'] ?? 0),
        );
    }

    protected function baseUrl(): string
    {
        $url = (string) config('runtime.llm.openai.base_url', '');

        return $url !== '' ? $url : 'https://api.openai.com';
    }
}
