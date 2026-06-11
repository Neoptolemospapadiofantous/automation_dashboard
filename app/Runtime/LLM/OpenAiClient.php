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
    public function complete(string $system, array $messages, array $tools = [], ?string $model = null, ?int $maxTokens = null): CompletionResult
    {
        $apiKey = (string) config('runtime.llm.openai.api_key');
        if ($apiKey === '') {
            throw new Misconfigured('OPENAI_API_KEY is not set — the ChatGPT tier cannot answer.');
        }

        $payload = [
            'model' => $model ?? (string) config('runtime.llm.openai.model_default'),
            'max_completion_tokens' => $maxTokens ?? (int) config('runtime.llm.anthropic.max_tokens'),
            'messages' => $this->toOpenAiMessages($system, $messages),
        ];
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
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    protected function toOpenAiMessages(string $system, array $messages): array
    {
        $out = [['role' => 'system', 'content' => $system]];

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
                $entry = ['role' => 'assistant', 'content' => $text !== '' ? $text : null];
                if ($toolCalls !== []) {
                    $entry['tool_calls'] = $toolCalls;
                }
                $out[] = $entry;

                continue;
            }

            // user message carrying tool_result blocks → role:tool entries
            foreach ((array) $content as $block) {
                if (($block['type'] ?? '') === 'tool_result') {
                    $out[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) $block['tool_use_id'],
                        'content' => (string) $block['content'],
                    ];
                }
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
            stopReason: $finish === 'tool_calls' ? 'tool_use' : 'end_turn',
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
