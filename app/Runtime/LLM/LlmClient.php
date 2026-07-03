<?php

namespace App\Runtime\LLM;

/**
 * Provider-agnostic chat-completion contract.
 *
 * The CANONICAL message format everywhere in the runtime (session
 * history, FlowExecutor, tools) is Anthropic-shaped:
 *   {role: user|assistant, content: string | content-blocks}
 *   blocks: {type: text|tool_use|tool_result, ...}
 * Non-Anthropic clients translate canonical → their wire format on the
 * way out and produce canonical contentBlocks on the way back, so
 * switching an agent's provider mid-conversation keeps its history
 * replayable.
 *
 * @api
 */
interface LlmClient
{
    /**
     * Run one completion turn.
     *
     * @param  string|list<array<string, mixed>>  $system  System prompt — a plain
     *                                                     string, or canonical (Anthropic-shaped) text blocks where a block may
     *                                                     carry `cache_control` to mark a cacheable prefix (see SystemPrompt).
     *                                                     Providers without explicit caching flatten blocks to text.
     * @param  list<array<string, mixed>>  $messages  Canonical messages
     * @param  list<array<string, mixed>>  $tools  Canonical tool specs
     *                                             ({name, description, input_schema})
     */
    public function complete(string|array $system, array $messages, array $tools = [], ?string $model = null, ?int $maxTokens = null): CompletionResult;
}
