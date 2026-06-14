<?php

namespace App\Runtime\Contracts;

use App\Runtime\Session\ConversationContext;

/**
 * A tool the agent can call mid-conversation. The LLM picks tools by name,
 * supplies a JSON argument bag, and the runtime dispatches to the matching
 * Tool::execute() — the return value gets fed back into the LLM as a
 * tool-result message so it can continue the reply with that information.
 *
 * Tools are the integration boundary between the conversational layer and
 * the rest of Flowstack: capture_lead writes to the leads table, query_kb
 * hits the KnowledgeBase, end_session closes the runtime session, etc.
 *
 * Implementations live in app/Runtime/Tools/.
 *
 * @api
 */
interface Tool
{
    /**
     * Unique tool name. Used by the LLM to identify which tool to call.
     * Convention: snake_case verbs. Examples: capture_lead, query_kb,
     * end_session, request_handoff.
     */
    public function name(): string;

    /**
     * Human-readable description fed to the LLM as part of the tool's
     * spec. The LLM uses this to decide WHEN to call the tool, so make
     * it explicit about the trigger condition.
     */
    public function description(): string;

    /**
     * JSON Schema for the tool's arguments. The LLM will produce JSON
     * matching this schema when it calls the tool.
     *
     * @return array<string, mixed>
     */
    public function parametersSchema(): array;

    /**
     * Execute the tool. $args has already been validated against
     * parametersSchema(). $context gives access to the current
     * conversation (agent, visitor, history, KB).
     *
     * Return value becomes the "tool result" content sent back to the
     * LLM as the next message in the conversation.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|string
     */
    public function execute(array $args, ConversationContext $context): array|string;
}
