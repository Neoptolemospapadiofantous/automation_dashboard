<?php

namespace App\Runtime\Automation;

use App\Models\AgentConfigVersion;

/**
 * The set of automations an agent currently exposes, read from its PUBLISHED
 * config version (`config['automations']`). Drafts are invisible — same rule
 * the rest of the runtime follows — so an operator can stage actions and
 * publish them atomically, with rollback for free.
 *
 * Malformed entries are silently dropped (AutomationAction::fromConfig), so a
 * typo in one action never breaks the others or the turn.
 */
class AutomationCatalog
{
    /** @var list<AutomationAction>|null */
    private ?array $actions = null;

    public function __construct(private readonly int $agentId) {}

    public static function forAgent(int $agentId): self
    {
        return new self($agentId);
    }

    /** @return list<AutomationAction> */
    public function all(): array
    {
        if ($this->actions !== null) {
            return $this->actions;
        }

        $config = AgentConfigVersion::publishedConfig($this->agentId) ?? [];
        $raw = $config['automations'] ?? [];

        $actions = [];
        $seen = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $action = AutomationAction::fromConfig($entry);
            // First definition wins on a duplicate name — a later dupe can't
            // shadow an earlier action the model was told about.
            if ($action === null || isset($seen[$action->name])) {
                continue;
            }
            $seen[$action->name] = true;
            $actions[] = $action;
        }

        return $this->actions = $actions;
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    public function find(string $name): ?AutomationAction
    {
        foreach ($this->all() as $action) {
            if ($action->name === $name) {
                return $action;
            }
        }

        return null;
    }

    /**
     * The "Available automations" block injected into the system prompt, or
     * '' when the agent has none configured.
     */
    public function promptCatalog(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        $lines = array_map(fn (AutomationAction $a): string => $a->catalogLine(), $this->all());

        return "Available automations (call the call_automation tool with the action name to run one):\n"
            .implode("\n", $lines);
    }
}
