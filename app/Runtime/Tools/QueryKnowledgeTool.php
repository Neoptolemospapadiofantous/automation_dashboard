<?php

namespace App\Runtime\Tools;

use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Contracts\Tool;
use App\Runtime\Session\ConversationContext;

/**
 * Let the model search the agent's knowledge base mid-conversation.
 *
 * The flow executor already auto-injects top KB chunks for the visitor's
 * latest message into the system prompt; this tool exists for the cases
 * where the model needs to dig deeper — a follow-up question, a different
 * phrasing, a detail the auto-context didn't surface.
 */
class QueryKnowledgeTool implements Tool
{
    public function __construct(protected KnowledgeStore $knowledge) {}

    public function name(): string
    {
        return 'query_kb';
    }

    public function description(): string
    {
        return 'Search the company knowledge base for facts you do not already have in context. '
            .'Use a focused natural-language question. Never invent product facts — if the KB '
            .'has no answer, say you will have a teammate follow up.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question' => ['type' => 'string', 'description' => 'Focused question to search for'],
                'top_k' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'How many passages to retrieve (default 5)'],
            ],
            'required' => ['question'],
        ];
    }

    public function execute(array $args, ConversationContext $context): array|string
    {
        $question = trim((string) ($args['question'] ?? ''));
        if ($question === '') {
            return 'No question provided.';
        }

        $default = max(1, (int) config('runtime.rag.retrieval_top_k', 5));
        $topK = isset($args['top_k']) ? max(1, min(10, (int) $args['top_k'])) : $default;

        $results = $this->knowledge->search($context->agent->id, $question, $topK);
        if ($results === []) {
            return 'No relevant information found in the knowledge base.';
        }

        $lines = [];
        foreach ($results as $i => $r) {
            $lines[] = sprintf('[%d] (%s) %s', $i + 1, $r['document_title'], $r['chunk']);
        }

        return implode("\n\n", $lines);
    }
}
