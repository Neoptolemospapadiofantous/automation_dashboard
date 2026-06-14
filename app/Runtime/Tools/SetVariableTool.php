<?php

namespace App\Runtime\Tools;

use App\Runtime\Contracts\Tool;
use App\Runtime\Session\ConversationContext;

/**
 * Remember a fact about the visitor across turns (budget, timeline,
 * team size, product interest, ...). Stored in the session's variables
 * JSON; surfaced back to the model in the system prompt each turn so
 * it doesn't re-ask.
 *
 * Keys are namespaced implicitly by convention (plain snake_case);
 * internal runtime bookkeeping uses an underscore prefix (_turns etc.)
 * which this tool refuses to overwrite.
 */
class SetVariableTool implements Tool
{
    public function name(): string
    {
        return 'set_variable';
    }

    public function description(): string
    {
        return 'Remember a fact about the visitor for the rest of the conversation '
            .'(e.g. budget="under $500/mo", timeline="this quarter", team_size="12"). '
            .'Use snake_case names. Call once per fact, as soon as you learn it.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'snake_case variable name'],
                'value' => ['type' => 'string', 'description' => 'The value to remember'],
            ],
            'required' => ['name', 'value'],
        ];
    }

    public function execute(array $args, ConversationContext $context): array|string
    {
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '' || str_starts_with($name, '_')) {
            return 'Invalid variable name.';
        }

        $vars = (array) ($context->session->variables ?? []);
        $vars[$name] = (string) ($args['value'] ?? '');
        $context->session->variables = $vars;
        $context->session->save();

        return ['status' => 'saved', 'name' => $name];
    }
}
