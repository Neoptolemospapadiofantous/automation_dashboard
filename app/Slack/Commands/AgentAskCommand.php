<?php

namespace App\Slack\Commands;

use App\Slack\SlackAgentResponder;

/**
 * `/agent <question>` — routes a question through the LLM agent runtime and
 * returns the reply. Spends credits, so it is admin-only (the router enforces
 * the SLACK_ADMIN_USERS allowlist before this runs).
 */
class AgentAskCommand implements SlashCommand
{
    public function __construct(
        private readonly SlackAgentResponder $responder,
    ) {}

    public function name(): string
    {
        return 'agent';
    }

    public function requiresAdmin(): bool
    {
        return true;
    }

    public function handle(SlashContext $ctx): string
    {
        $question = trim($ctx->text);
        if ($question === '') {
            return 'Usage: `/agent <your question>`';
        }

        return $this->responder->reply($ctx->userId, $ctx->channelId, $question);
    }
}
