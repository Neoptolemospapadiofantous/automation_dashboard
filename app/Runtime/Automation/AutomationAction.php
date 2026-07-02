<?php

namespace App\Runtime\Automation;

use App\Models\AutomationRun;

/**
 * One operator-configured automation the agent can invoke as a tool. Built
 * from a single entry in the published config's `automations` array; invalid
 * entries are dropped by AutomationCatalog before they ever reach here, so an
 * instance is always well-formed.
 *
 * Immutable value object — no behavior beyond shaping itself for the LLM
 * (the system-prompt catalog line) and exposing its execution settings.
 */
class AutomationAction
{
    /**
     * @param  array<string, mixed>  $parameters  JSON Schema for arguments.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $url,
        public readonly string $mode,
        public readonly int $creditCost,
        public readonly array $parameters,
    ) {}

    /**
     * Build from a raw config entry, or null if it's missing the essentials
     * (name + url). Defaults are applied here so the rest of the system can
     * trust the shape.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromConfig(array $raw): ?self
    {
        $name = self::normalizeName((string) ($raw['name'] ?? ''));
        $url = trim((string) ($raw['url'] ?? ''));
        if ($name === '' || $url === '') {
            return null;
        }

        $mode = ($raw['mode'] ?? AutomationRun::MODE_SYNC) === AutomationRun::MODE_ASYNC
            ? AutomationRun::MODE_ASYNC
            : AutomationRun::MODE_SYNC;

        $parameters = is_array($raw['parameters'] ?? null)
            ? $raw['parameters']
            : ['type' => 'object', 'properties' => (object) []];

        return new self(
            name: $name,
            description: trim((string) ($raw['description'] ?? '')),
            url: $url,
            mode: $mode,
            creditCost: max(1, (int) ($raw['credit_cost'] ?? 1)),
            parameters: $parameters,
        );
    }

    public function isAsync(): bool
    {
        return $this->mode === AutomationRun::MODE_ASYNC;
    }

    /**
     * The line describing this action in the system-prompt catalog, so the
     * model knows the action exists, when to call it, and what arguments it
     * expects (the call_automation tool takes free-form arguments — this
     * schema is the model's only source for their shape).
     */
    public function catalogLine(): string
    {
        $desc = $this->description !== '' ? $this->description : 'No description provided.';
        $timing = $this->isAsync() ? 'runs in the background' : 'returns a result';

        $line = "- {$this->name} ({$timing}): {$desc}";

        $properties = $this->parameters['properties'] ?? [];
        if (is_object($properties)) {
            $properties = get_object_vars($properties);
        }
        if (is_array($properties) && $properties !== []) {
            $schema = json_encode($this->parameters);
            if ($schema !== false) {
                $line .= " Arguments (JSON Schema): {$schema}";
            }
        }

        return $line;
    }

    /**
     * snake_case identifier the LLM uses to name the action. Mirrors tool
     * naming convention; strips anything that isn't [a-z0-9_].
     */
    private static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9]+/', '_', $name) ?? '';

        return trim($name, '_');
    }
}
