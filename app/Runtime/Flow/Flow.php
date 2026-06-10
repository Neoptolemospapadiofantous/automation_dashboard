<?php

namespace App\Runtime\Flow;

/**
 * A conversation flow: named states + which one starts the session.
 *
 * Concrete flows (LeadCaptureFlow today; support/scheduling templates
 * later) define states() declaratively. resolve() falls back to the
 * initial state for unknown values so a session that persisted a state
 * name from an older flow revision degrades gracefully instead of 500ing.
 */
abstract class Flow
{
    /**
     * @return array<string, State>
     */
    abstract public function states(): array;

    abstract public function initial(): string;

    public function resolve(string $name): State
    {
        $states = $this->states();

        return $states[$name] ?? $states[$this->initial()];
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->states());
    }
}
