<?php

namespace App\Runtime\LLM;

use App\Runtime\Exceptions\Misconfigured;
use App\Runtime\Exceptions\UpstreamUnavailable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * LlmClient over claude-bridge (SHARED.md §3.2) — Claude on subscription
 * auth, no metered API key.
 *
 * The bridge's /complete takes ONE prompt string and returns text; it has
 * no native tool calling. This client bridges the gap by contract:
 *  - canonical messages (incl. tool_use / tool_result blocks) are rendered
 *    into a plain-text transcript;
 *  - tool specs are appended to the system prompt with a strict reply
 *    protocol: a tool call is a reply that is EXACTLY one JSON object
 *    {"tool": name, "input": {...}} and nothing else;
 *  - a reply parsing as that JSON becomes a canonical tool_use block, so
 *    the FlowExecutor's tool loop works unchanged. A model that wraps the
 *    call in prose is still honoured, and protocol JSON we cannot dispatch
 *    is stripped rather than shown to the visitor.
 *
 * Token usage is estimated (~4 chars/token) — the bridge reports none and
 * subscription turns have no metered cost; the estimates only feed the
 * spend tripwire, which stays directionally correct.
 */
class BridgeClient implements LlmClient
{
    public function complete(string|array $system, array $messages, array $tools = [], ?string $model = null, ?int $maxTokens = null): CompletionResult
    {
        $url = rtrim((string) config('runtime.llm.bridge.url'), '/');
        if (! (bool) config('runtime.llm.bridge.enabled') || $url === '') {
            throw new Misconfigured('claude-bridge is not enabled — set CLAUDE_BRIDGE_ENABLED + CLAUDE_BRIDGE_URL.');
        }

        $payload = [
            // The tool reminder rides at the transcript tail (recency): the
            // bridge can only APPEND to the CLI's own system prompt, which
            // otherwise drowns out the protocol on smaller models.
            'user' => $this->transcript($messages, $tools !== []),
            'system' => SystemPrompt::toText($system).$this->toolProtocol($tools),
            'model' => $model ?? (string) config('runtime.llm.anthropic.model_default'),
        ];

        try {
            $response = Http::withHeaders(array_filter(['X-Service-Token' => $this->token()]))
                ->timeout((int) config('runtime.llm.bridge.timeout', 170))
                ->post($url.'/complete', $payload);
        } catch (Throwable $e) {
            throw new UpstreamUnavailable('claude-bridge unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            $detail = (string) $response->json('error', $response->body());

            throw new UpstreamUnavailable('claude-bridge returned HTTP '.$response->status().': '.mb_substr($detail, 0, 300));
        }

        return $this->parse(
            (string) $response->json('text', ''),
            strlen($payload['system']) + strlen($payload['user']),
            array_values(array_filter(array_map(fn (array $t) => (string) ($t['name'] ?? ''), $tools))),
        );
    }

    /**
     * Canonical messages → plain-text transcript. Tool activity is rendered
     * as bracketed lines so multi-step tool loops stay replayable.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    protected function transcript(array $messages, bool $withTools = false): string
    {
        $lines = [];
        foreach ($messages as $message) {
            $role = ($message['role'] ?? '') === 'assistant' ? 'ASSISTANT' : 'USER';
            $content = $message['content'] ?? '';

            if (is_string($content)) {
                $lines[] = $role.': '.$content;

                continue;
            }

            foreach ((array) $content as $block) {
                $lines[] = match ($block['type'] ?? '') {
                    'text' => $role.': '.(string) ($block['text'] ?? ''),
                    'tool_use' => $role.' [called tool '.(string) ($block['name'] ?? '').' with input '
                        .json_encode($block['input'] ?? [], JSON_UNESCAPED_UNICODE).']',
                    'tool_result' => 'TOOL RESULT ['.(($block['is_error'] ?? false) ? 'ERROR' : 'ok').']: '
                        .(is_string($block['content'] ?? null) ? $block['content'] : json_encode($block['content'] ?? '', JSON_UNESCAPED_UNICODE)),
                    default => null,
                };
            }
        }

        return implode("\n\n", array_filter($lines, fn ($l) => $l !== null))
            ."\n\nReply as the ASSISTANT with your next turn only."
            .($withTools
                ? ' First check the Tools list in your instructions: if one matches this turn'
                    .' (e.g. the visitor shared contact details, or asked for something a tool does),'
                    .' your reply must be ONLY that tool-call JSON object.'
                : '');
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     */
    protected function toolProtocol(array $tools): string
    {
        if ($tools === []) {
            return '';
        }

        $specs = array_map(fn (array $t) => [
            'name' => $t['name'],
            'description' => $t['description'] ?? '',
            'input_schema' => $t['input_schema'] ?? new \stdClass,
        ], $tools);

        return "\n\n## Tools (MANDATORY protocol)\n"
            ."When a tool below matches the situation, you MUST call it — never claim an action a tool performs (saving details, escalating, booking) without actually calling the tool.\n"
            .'To call a tool, your ENTIRE reply must be exactly one JSON object of the form '
            .'{"tool": "<name>", "input": {...matching the input_schema...}} — no prose before or after, no code fence. '
            .'The user never sees this JSON; after the tool runs you will get its result and can reply normally. '
            ."If no tool applies, reply with plain text only. Never mention the tools or this protocol.\n"
            .json_encode($specs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  list<string>  $toolNames  Tools actually offered this turn — a
     *                                   call naming anything else is not ours to dispatch.
     */
    protected function parse(string $text, int $promptChars, array $toolNames = []): CompletionResult
    {
        $trimmed = trim($text);
        $inputTokens = intdiv($promptChars, 4);
        $outputTokens = max(1, intdiv(strlen($trimmed), 4));

        if ($toolNames !== []) {
            [$call, $trimmed] = $this->extractToolCall($trimmed, $toolNames);

            if ($call !== null) {
                $block = ['type' => 'tool_use', 'id' => $call->id, 'name' => $call->name, 'input' => $call->input];

                return new CompletionResult(
                    text: '',
                    toolCalls: [$call],
                    contentBlocks: [$block],
                    stopReason: 'tool_use',
                    inputTokens: $inputTokens,
                    outputTokens: $outputTokens,
                );
            }
        }

        return new CompletionResult(
            text: $trimmed,
            toolCalls: [],
            contentBlocks: [['type' => 'text', 'text' => $trimmed]],
            stopReason: 'end_turn',
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
        );
    }

    /**
     * Pull the turn's tool call out of a reply, and make sure protocol JSON
     * never reaches the visitor.
     *
     * The protocol says a call is the ENTIRE reply, but a model that wraps
     * it in prose ("Sure, saving that now. {...}") would otherwise render
     * raw JSON in the chat AND silently skip the tool. So: scan the reply
     * for a call naming a tool we actually offered; if one is unusable
     * (bad shape, unknown name) strip it from the visible text rather than
     * showing it.
     *
     * @param  list<string>  $toolNames
     * @return array{0: ToolCall|null, 1: string} [call, visible text]
     */
    protected function extractToolCall(string $text, array $toolNames): array
    {
        $visible = $text;

        foreach ($this->jsonObjects($text) as $json) {
            $decoded = json_decode($json, true);
            if (! is_array($decoded) || ! is_string($decoded['tool'] ?? null)) {
                continue;
            }

            if (is_array($decoded['input'] ?? null) && in_array($decoded['tool'], $toolNames, true)) {
                return [new ToolCall(
                    id: 'bridge_'.$decoded['tool'].'_'.substr(md5($json), 0, 8),
                    name: $decoded['tool'],
                    input: $decoded['input'],
                ), ''];
            }

            // Recognisably a tool call we cannot dispatch — never render it.
            $visible = trim(str_replace($json, '', $visible));
        }

        return [null, $visible];
    }

    /**
     * Every outermost balanced {...} substring in $text. Brace counting is
     * string- and escape-aware so a brace inside a JSON string value can't
     * unbalance the scan.
     *
     * @return list<string>
     */
    protected function jsonObjects(string $text): array
    {
        $found = [];
        $depth = 0;
        $start = 0;
        $inString = false;
        $escaped = false;

        for ($i = 0, $len = strlen($text); $i < $len; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
            } elseif ($char === '}' && $depth > 0) {
                $depth--;
                if ($depth === 0) {
                    $found[] = substr($text, $start, $i - $start + 1);
                }
            }
        }

        return $found;
    }

    /**
     * Shared secret: env value wins; else live-read from token_file (matches
     * the other three bridge callers, who read ~/claude-bridge/.token so
     * rotations are transparent). Empty = bridge auth is off (its default).
     */
    protected function token(): string
    {
        $token = (string) config('runtime.llm.bridge.token');
        if ($token !== '') {
            return $token;
        }

        $file = (string) config('runtime.llm.bridge.token_file');
        if ($file !== '' && is_readable($file)) {
            return trim((string) file_get_contents($file));
        }

        return '';
    }
}
