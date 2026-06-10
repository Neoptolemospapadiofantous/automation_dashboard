<?php

namespace App\Runtime\Tools;

use App\Runtime\Contracts\Tool;
use App\Runtime\Session\ConversationContext;

/**
 * Flag the conversation for human follow-up. Sets handoff markers on the
 * session variables; the ops surface (and a future notification hook)
 * reads them. The model is instructed to set expectations with the
 * visitor ("a teammate will reach out") in its reply.
 */
class RequestHandoffTool implements Tool
{
    public function name(): string
    {
        return 'request_handoff';
    }

    public function description(): string
    {
        return 'Escalate to a human teammate. Call when the visitor explicitly asks for a person, '
            .'is frustrated, or asks something outside your scope (pricing exceptions, legal, '
            .'custom contracts). Tell the visitor a teammate will follow up.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => ['type' => 'string', 'description' => 'Why a human is needed, one sentence'],
            ],
            'required' => ['reason'],
        ];
    }

    public function execute(array $args, ConversationContext $context): array|string
    {
        $vars = (array) ($context->session->variables ?? []);
        $vars['handoff_requested'] = true;
        $vars['handoff_reason'] = trim((string) ($args['reason'] ?? '')) ?: 'unspecified';
        $context->session->variables = $vars;
        $context->session->save();

        return ['status' => 'handoff_flagged', 'message' => 'A teammate has been notified.'];
    }
}
