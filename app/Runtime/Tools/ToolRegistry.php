<?php

namespace App\Runtime\Tools;

use App\Runtime\Contracts\Tool;
use App\Runtime\LLM\ToolCall;
use App\Runtime\Session\ConversationContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Holds every Tool the runtime can offer the model, translates them into
 * Anthropic tool specs, and dispatches tool_use calls back to the matching
 * implementation.
 *
 * Tool failures NEVER crash the turn — the error message is returned to
 * the model as an is_error tool_result so it can apologize / retry /
 * route around the failure. The exception is still logged for ops.
 */
class ToolRegistry
{
    /** @var array<string, Tool> */
    protected array $tools = [];

    public function register(Tool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /**
     * Anthropic-format specs for a subset of registered tools (the
     * current flow state decides which tools the model may use).
     *
     * @param  list<string>  $names
     * @return list<array<string, mixed>>
     */
    public function specs(array $names): array
    {
        $specs = [];
        foreach ($names as $name) {
            $tool = $this->tools[$name] ?? null;
            if ($tool === null) {
                continue; // state references a tool that isn't registered — skip, don't crash
            }
            $specs[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'input_schema' => $tool->parametersSchema(),
            ];
        }

        return $specs;
    }

    /**
     * Execute one tool_use call. Returns the Anthropic tool_result content
     * block payload: {content: string, is_error: bool}.
     *
     * @return array{content: string, is_error: bool}
     */
    public function dispatch(ToolCall $call, ConversationContext $context): array
    {
        $tool = $this->tools[$call->name] ?? null;
        if ($tool === null) {
            return [
                'content' => "Tool '{$call->name}' is not available.",
                'is_error' => true,
            ];
        }

        try {
            $result = $tool->execute($call->input, $context);

            return [
                'content' => is_string($result) ? $result : (string) json_encode($result, JSON_UNESCAPED_SLASHES),
                'is_error' => false,
            ];
        } catch (Throwable $e) {
            Log::warning('Runtime tool failed', [
                'tool' => $call->name,
                'agent_id' => $context->agent->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'content' => "The {$call->name} action failed: ".$e->getMessage(),
                'is_error' => true,
            ];
        }
    }
}
