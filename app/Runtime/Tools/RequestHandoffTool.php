<?php

namespace App\Runtime\Tools;

use App\Runtime\Contracts\Tool;
use App\Runtime\Session\ConversationContext;
use App\Runtime\Support\EscalateToHuman;

/**
 * Flag the conversation for human follow-up. Sets handoff markers on the
 * session variables; the ops surface (and a future notification hook)
 * reads them. The model is instructed to set expectations with the
 * visitor ("a teammate will reach out") in its reply.
 *
 * The actual escalation side-effect lives in EscalateToHuman, shared with
 * FlowExecutor's confidence gate so the two paths never drift.
 */
class RequestHandoffTool implements Tool
{
    public function __construct(protected EscalateToHuman $escalate) {}

    public function name(): string
    {
        return 'request_handoff';
    }

    public function description(): string
    {
        return 'Escalate to a human teammate. Call when the visitor explicitly asks for a person, '
            .'is frustrated, or asks something outside your scope (pricing exceptions, legal, '
            .'custom contracts). Tell the visitor a teammate will follow up. If you do not have '
            .'the visitor\'s contact details yet, ask for an email or phone number in the same '
            .'reply and save it with capture_lead — without contact the team cannot follow up.';
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
        $this->escalate->handle($context, (string) ($args['reason'] ?? ''));

        // Contact-aware result: an anonymous handoff is an un-followable
        // promise, so steer the model to collect a reachable detail NOW.
        if (! $this->escalate->hasContact($context)) {
            return [
                'status' => 'handoff_flagged',
                'message' => 'A teammate has been notified — but NO contact details are on file for '
                    .'this visitor. In your reply, ask for an email address or phone number so the '
                    .'team can follow up, then save it with capture_lead.',
            ];
        }

        return ['status' => 'handoff_flagged', 'message' => 'A teammate has been notified.'];
    }
}
