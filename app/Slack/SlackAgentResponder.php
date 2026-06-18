<?php

namespace App\Slack;

use App\Billing\CreditMeter;
use App\Billing\Exceptions\OutOfCredits;
use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\Team;
use App\Runtime\Contracts\Runtime;
use Illuminate\Support\Facades\Log;

/**
 * Bridges a Slack message into the existing agent runtime and bills it exactly
 * like a web turn (see ChatController): pre-check credits → run the turn →
 * consume (1 + reply-count) * creditsPerMessage. Single-tenant: one local Team
 * (config services.slack.team_id) owns billing and supplies the answering agent.
 *
 * Session continuity uses a per-(user,channel) visitor id, so a Slack thread
 * keeps context the same way an embed visitor does.
 */
class SlackAgentResponder
{
    public function __construct(
        private readonly Runtime $runtime,
        private readonly CreditMeter $credits,
    ) {}

    /**
     * Generate and return the agent's reply text for a Slack message, or a
     * human-readable status string when the agent can't answer (not configured,
     * out of credits, runtime error). Never throws.
     */
    public function reply(string $slackUserId, string $channel, string $text): string
    {
        $team = $this->team();
        if ($team === null) {
            return ':warning: No Slack team is configured (SLACK_TEAM_ID).';
        }

        $agent = $this->agent($team);
        if ($agent === null) {
            return ':warning: No active agent is set up for this team yet.';
        }

        if (! $team->hasCredits()) {
            return ':credit_card: This team is out of credits — top up to keep the agent answering.';
        }

        $visitorId = "slack:{$slackUserId}:{$channel}";

        try {
            $traces = $this->runtime->sendText($agent, $visitorId, $text);
        } catch (\RuntimeException $e) {
            report($e);

            return ':warning: The agent is temporarily unavailable.';
        }

        $messages = $this->messagesFrom($traces);

        // Bill the same shape as the web paths: 1 user message + N replies.
        $billed = (1 + count($messages)) * AgentConfigVersion::creditsPerMessage($agent->id);
        try {
            $this->credits->consume(
                team: $team,
                amount: $billed,
                agentId: $agent->id,
                meta: ['surface' => 'slack', 'slack_user_id' => $slackUserId, 'channel' => $channel],
            );
        } catch (OutOfCredits) {
            // Raced past zero between the pre-check and the debit — the reply is
            // already generated, so deliver it but flag the empty tank.
            Log::warning("SlackAgentResponder: credit debit raced past zero for team {$team->id}.");
        }

        $reply = trim(implode("\n\n", $messages));

        return $reply !== '' ? $reply : ':speech_balloon: (the agent had nothing to add)';
    }

    /**
     * Flatten the runtime's text traces into reply strings.
     *
     * @param  array<int,array<string,mixed>>  $traces
     * @return list<string>
     */
    private function messagesFrom(array $traces): array
    {
        $out = [];
        foreach ($traces as $trace) {
            if (($trace['type'] ?? '') !== 'text') {
                continue;
            }
            $message = trim((string) ($trace['payload']['message'] ?? ''));
            if ($message !== '') {
                $out[] = $message;
            }
        }

        return $out;
    }

    private function team(): ?Team
    {
        $id = config('services.slack.team_id');

        return $id !== null && $id !== '' ? Team::find($id) : null;
    }

    private function agent(Team $team): ?Agent
    {
        $configured = config('services.slack.agent_id');
        if ($configured !== null && $configured !== '') {
            $agent = Agent::where('team_id', $team->id)->find($configured);
            if ($agent !== null) {
                return $agent;
            }
        }

        /** @var Agent|null $current */
        $current = $team->currentAgent;

        return $current;
    }
}
