<?php

namespace App\Lifecycle;

use App\Events\Domain\ConversationEnded;

/**
 * Conversation status flow:
 *
 *   active ─► ended  (terminal)
 *
 * A conversation that ends and then resumes (rare) gets a
 * new row instead — keeps the audit trail honest and avoids any "did the
 * conversation actually end?" ambiguity in analytics.
 */
class ConversationStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            new Transition(
                from: 'active',
                to: 'ended',
                event: ConversationEnded::class,
            ),
        ];
    }
}
