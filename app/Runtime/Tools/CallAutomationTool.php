<?php

namespace App\Runtime\Tools;

use App\Runtime\Automation\AutomationCatalog;
use App\Runtime\Automation\AutomationDispatcher;
use App\Runtime\Contracts\Tool;
use App\Runtime\Session\ConversationContext;

/**
 * The wedge: lets the agent run an operator-configured n8n automation
 * mid-conversation (chat = brain, n8n = hands). One dispatch tool, not one
 * tool per action — the model learns WHICH automations exist and WHEN to call
 * them from the "Available automations" catalog injected into the system
 * prompt; here it just names the action and passes arguments.
 *
 * Everything risky lives behind AutomationDispatcher: feature flag, billing
 * before firing, SSRF guard, HMAC signing, idempotency, circuit breaker, and
 * the automation_runs audit row. A failure here never crashes the turn — the
 * ToolRegistry returns it to the model as an is_error result.
 */
class CallAutomationTool implements Tool
{
    public function __construct(private readonly AutomationDispatcher $dispatcher) {}

    public function name(): string
    {
        return 'call_automation';
    }

    public function description(): string
    {
        return 'Run one of this agent\'s configured automations (e.g. look something up in an '
            .'external system, create a ticket, trigger a workflow). The available automations and '
            .'when to use each are listed in "Available automations" in your instructions. Pass the '
            .'exact action name and the arguments that automation expects. Only call this for an '
            .'action that is actually listed — never invent one.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => 'The exact name of the automation to run, as listed in "Available automations".',
                ],
                'arguments' => [
                    'type' => 'object',
                    'description' => 'The arguments for the automation, matching what that action expects. Omit or pass {} if it takes none.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $args, ConversationContext $context): array|string
    {
        if (! (bool) config('runtime.automation.enabled', false)) {
            return ['status' => 'error', 'message' => 'Automations are not enabled for this agent.'];
        }

        $actionName = trim((string) ($args['action'] ?? ''));
        if ($actionName === '') {
            return ['status' => 'error', 'message' => 'No automation action was specified.'];
        }

        $action = AutomationCatalog::forAgent($context->agent->id)->find($actionName);
        if ($action === null) {
            return ['status' => 'error', 'message' => "There is no automation named '{$actionName}' configured for this agent."];
        }

        $arguments = $args['arguments'] ?? [];
        if (! is_array($arguments)) {
            $arguments = [];
        }

        return $this->dispatcher->dispatch($context->agent, $action, $arguments, $context->session);
    }
}
