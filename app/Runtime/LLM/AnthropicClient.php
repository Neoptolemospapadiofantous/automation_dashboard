<?php

namespace App\Runtime\LLM;

use App\Runtime\Exceptions\Misconfigured;
use App\Runtime\Exceptions\UpstreamUnavailable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin client for the Anthropic Messages API with tool calling.
 *
 * Deliberately NOT the official SDK: the surface we need is one endpoint
 * (POST /v1/messages), Laravel's Http facade gives us Http::fake() in
 * tests for free, and retry/backoff is two lines. Streaming (SSE
 * pass-through) is a Phase 7+ refinement — the flow executor currently
 * emits stage-level events, which is what the embed UI consumes.
 *
 * All failures normalize into the Runtime exception hierarchy:
 *   - Misconfigured       — no API key set
 *   - UpstreamUnavailable — Anthropic returned an error / unreachable
 */
class AnthropicClient implements LlmClient
{
    /**
     * Run one completion turn.
     *
     * @param  string|list<array<string, mixed>>  $system  System prompt (string, or cacheable blocks from SystemPrompt::blocks)
     * @param  list<array<string, mixed>>  $messages  Anthropic-format messages
     * @param  list<array<string, mixed>>  $tools  Anthropic-format tool specs
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
        $apiKey = $this->apiKeyOverride ?? (string) config('runtime.llm.anthropic.api_key');
        if ($apiKey === '') {
            throw new Misconfigured('ANTHROPIC_API_KEY is not set — the native runtime cannot call the LLM.');
        }

        $payload = [
            'model' => $model ?? (string) config('runtime.llm.anthropic.model_default'),
            'max_tokens' => $maxTokens ?? (int) config('runtime.llm.anthropic.max_tokens'),
            // Blocks carrying cache_control (from SystemPrompt::blocks) ride
            // through verbatim so Anthropic caches the stable prefix.
            'system' => $system,
            'messages' => $messages,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ])
                ->timeout(60)
                // Retry transient failures (rate limits + 5xx) twice with
                // backoff; final failure falls through to failed() below.
                ->retry(2, 300, function (Throwable $e): bool {
                    return $e instanceof RequestException
                        && in_array($e->response->status(), [429, 500, 502, 503, 529], true);
                }, throw: false)
                ->post('/v1/messages', $payload);
        } catch (Throwable $e) {
            throw new UpstreamUnavailable('Anthropic unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            $detail = (string) $response->json('error.message', $response->body());

            throw new UpstreamUnavailable(
                'Anthropic returned HTTP '.$response->status().': '.mb_substr($detail, 0, 300),
            );
        }

        return $this->parse($response->json());
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function parse(?array $body): CompletionResult
    {
        $blocks = is_array($body['content'] ?? null) ? $body['content'] : [];

        $text = '';
        $toolCalls = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
            if (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: (string) ($block['id'] ?? ''),
                    name: (string) ($block['name'] ?? ''),
                    input: is_array($block['input'] ?? null) ? $block['input'] : [],
                );
            }
        }

        return new CompletionResult(
            text: $text,
            toolCalls: $toolCalls,
            contentBlocks: array_values(array_filter($blocks, 'is_array')),
            stopReason: (string) ($body['stop_reason'] ?? 'end_turn'),
            inputTokens: (int) ($body['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
        );
    }

    protected function baseUrl(): string
    {
        $url = (string) config('runtime.llm.anthropic.base_url', '');

        return $url !== '' ? $url : 'https://api.anthropic.com';
    }
}
