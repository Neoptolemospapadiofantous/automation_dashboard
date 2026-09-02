<?php

namespace App\Runtime\Collab;

use App\Billing\CreditMeter;
use App\Billing\Exceptions\OutOfCredits;
use App\Billing\OwnKey;
use App\Models\Agent;
use App\Models\Team;
use App\Runtime\Contracts\Runtime;

/**
 * Agent-to-agent communication: the primitive that turns one agent's output
 * into another agent's input. Each agent keeps its own runtime session keyed
 * by the room, so it *remembers* the exchange (memory), every turn is written
 * to the RoomLedger (shared record), and credits are debited per turn with the
 * same formula the web/CLI surfaces use (consistent books).
 */
class AgentConversation
{
    public function __construct(
        protected Runtime $runtime,
        protected CreditMeter $credits,
        protected RoomLedger $ledger,
    ) {}

    /**
     * Deliver one message to an agent inside a room and return its reply
     * lines. The agent answers in its own persona/session.
     *
     * @return list<string>
     */
    public function relay(Agent $agent, string $message, string $room, bool $bill = true): array
    {
        $visitor = 'room:'.$room;

        if ($bill && $agent->team instanceof Team && ! $agent->team->hasCredits()) {
            $this->ledger->append($room, [
                'agent_id' => $agent->id, 'agent' => $agent->name,
                'text' => '[skipped: team out of credits]', 'error' => 'out_of_credits',
            ]);

            return [];
        }

        $replies = [];
        foreach ($this->runtime->sendText($agent, $visitor, $message) as $trace) {
            $text = (string) ($trace['payload']['message'] ?? '');
            if ($text !== '') {
                $replies[] = $text;
            }
        }

        $this->ledger->append($room, [
            'agent_id' => $agent->id,
            'agent' => $agent->name,
            'team_id' => $agent->team_id,
            'in' => $message,
            'text' => implode("\n\n", $replies),
        ]);

        if ($bill && $agent->team instanceof Team) {
            $amount = (1 + count($replies)) * app(OwnKey::class)->effectiveCreditsPerMessage($agent);
            try {
                $this->credits->consume(
                    team: $agent->team,
                    amount: $amount,
                    agentId: $agent->id,
                    meta: ['surface' => 'collab', 'room' => $room],
                );
            } catch (OutOfCredits) {
                // Reply already produced; next turn's pre-check will block.
            }
        }

        return $replies;
    }

    /**
     * Run a chain where each agent responds to the previous speaker's message,
     * for the given number of rounds. The first agent answers the topic; every
     * later turn receives the prior agent's reply. Returns the full transcript.
     *
     * @param  list<Agent>  $agents
     * @return list<array{agent: string, agent_id: int, text: string}>
     */
    public function roundtable(array $agents, string $topic, int $rounds, string $room, bool $bill = true): array
    {
        $transcript = [];
        $last = $topic;
        $first = true;

        for ($r = 0; $r < max(1, $rounds); $r++) {
            foreach ($agents as $agent) {
                $prompt = $first
                    ? $topic
                    : "A teammate said:\n\n".$last."\n\nReply with your perspective.";
                $first = false;

                $replies = $this->relay($agent, $prompt, $room, $bill);
                $text = implode("\n\n", $replies);
                if ($text !== '') {
                    $last = $text;
                }

                $transcript[] = ['agent' => $agent->name, 'agent_id' => $agent->id, 'text' => $text];
            }
        }

        return $transcript;
    }
}
