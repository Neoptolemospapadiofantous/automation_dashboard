<?php

namespace App\Runtime\Tools;

use App\Runtime\Contracts\Tool;
use App\Runtime\Session\ConversationContext;

/**
 * The model's explicit "this turn needs no human" signal on a
 * low-confidence turn. Deliberately a no-op: its whole value is that
 * calling it is a POSITIVE choice the deterministic backstop in
 * FlowExecutor can trust — absence of request_handoff alone cannot
 * distinguish "the model judged this small talk" from "the model forgot
 * to escalate", and the backstop used to treat both as forgetting, which
 * escalated goodbyes and off-topic jokes to a human (and a phone ring).
 *
 * Only offered on low-confidence turns, alongside request_handoff.
 */
class NoHandoffNeededTool implements Tool
{
    public function name(): string
    {
        return 'no_handoff_needed';
    }

    public function description(): string
    {
        return 'Declare that the visitor\'s current message needs NO human handoff: it is small '
            .'talk, thanks, a goodbye, or clearly unrelated to the company — not a real question '
            .'about the product, pricing, or the company. Call it instead of request_handoff on '
            .'such turns, then reply with one short, friendly line and steer gently back. Never '
            .'ask for contact details on such a turn.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => ['type' => 'string', 'description' => 'Why no human is needed, a few words'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $args, ConversationContext $context): array|string
    {
        return ['status' => 'ok', 'message' => 'No handoff flagged. Reply with one short, friendly line.'];
    }
}
