<?php

namespace App\Runtime\LLM;

/**
 * Result of one Anthropic messages call.
 *
 * text          — concatenated text blocks (may be '' on pure tool turns)
 * toolCalls     — tool_use blocks the model emitted this turn
 * contentBlocks — the RAW content array from the response. The flow
 *                 executor appends this verbatim as the assistant message
 *                 when sending tool_results back — Anthropic requires the
 *                 tool_use blocks to be present in the prior assistant
 *                 message for the tool_result pairing to validate.
 * stopReason    — 'end_turn' | 'tool_use' | 'max_tokens' | ...
 */
class CompletionResult
{
    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  list<array<string, mixed>>  $contentBlocks
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly array $contentBlocks,
        public readonly string $stopReason,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
    ) {}

    public function wantsTools(): bool
    {
        return $this->stopReason === 'tool_use' && $this->toolCalls !== [];
    }
}
