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
     * @param  string  $system  System prompt
     * @param  list<array<string, mixed>>  $messages  Canonical messages
     * @param  list<array<string, mixed>>  $tools  Canonical tool specs
     *                                             ({name, description, input_schema})
     */
    public function complete(string $system, array $messages, array $tools = [], ?string $model = null, ?int $maxTokens = null): CompletionResult;
}
