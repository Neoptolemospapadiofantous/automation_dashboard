<?php

namespace App\Runtime\Tools;

use App\Runtime\Contracts\Tool;
use App\Runtime\Session\ConversationContext;

/**
 * Signal that the conversation is complete. The state transition itself
 * (flow_state → 'ended') is applied centrally by the FlowExecutor via the
 * state's onToolSuccess map — tools never write flow_state directly, so
 * transition logic lives in exactly one place.
 */
class EndSessionTool implements Tool
{
    public function name(): string
    {
        return 'end_session';
    }

    public function description(): string
    {
        return 'Close the conversation. Call when the visitor says goodbye, confirms they have '
            .'everything they need, or explicitly asks to end the chat. Say a brief farewell '
            .'in your reply after calling this.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => ['type' => 'string', 'description' => 'One short phrase: why the session ended'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $args, ConversationContext $context): array|string
    {
        $vars = (array) ($context->session->variables ?? []);
        $vars['ended_reason'] = trim((string) ($args['reason'] ?? '')) ?: 'visitor done';
        $context->session->variables = $vars;
        $context->session->save();

        return ['status' => 'session_closing'];
    }
}
