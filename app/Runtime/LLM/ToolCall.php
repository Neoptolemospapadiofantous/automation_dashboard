<?php

namespace App\Runtime\LLM;

/**
 * One tool invocation requested by the model. Mirrors Anthropic's
 * `tool_use` content block: the id pairs the eventual tool_result back
 * to this call, name selects the Tool implementation, input is the
 * JSON argument bag (already decoded).
 */
class ToolCall
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input,
    ) {}
}
