<?php

namespace App\Runtime\Tools;

use App\Models\Team;
use App\Models\User;
use App\Notifications\HandoffRequestedNotification;
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
        $reason = trim((string) ($args['reason'] ?? '')) ?: 'unspecified';

        $vars = (array) ($context->session->variables ?? []);
        $vars['handoff_requested'] = true;
        $vars['handoff_reason'] = $reason;
        $context->session->variables = $vars;
        $context->session->save();

        // Make the "a teammate has been notified" promise TRUE: bell + email
        // to the team owner. Best-effort — a mail hiccup must not fail the
        // visitor's turn (the session flag above is the durable record).
        rescue(function () use ($context, $reason): void {
            $team = $context->agent->team;
            $owner = $team instanceof Team ? $team->owner : null;
            if ($owner instanceof User) {
                $owner->notify(new HandoffRequestedNotification(
                    agent: $context->agent,
                    visitorId: $context->session->visitor_id,
                    reason: $reason,
                ));
            }
        }, report: true);

        return ['status' => 'handoff_flagged', 'message' => 'A teammate has been notified.'];
    }
}
