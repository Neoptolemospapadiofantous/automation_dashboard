<?php

namespace App\Lifecycle;

use BackedEnum;
use Closure;

/**
 * A single allowed move between two states.
 *
 * Optional guard: a closure that receives the model and returns true to allow
 * the transition (e.g. "an agent can only go draft → active if its last health
 * check passed"). Optional event: a domain event class fired after the
 * transition lands. Both are nullable so trivial transitions stay one line.
 *
 * @template TState of BackedEnum|string
 */
final class Transition
{
    /**
     * @param  TState  $from
     * @param  TState  $to
     * @param  (Closure(object): bool)|null  $guard
     * @param  class-string|null  $event
     */
    public function __construct(
        public readonly BackedEnum|string $from,
        public readonly BackedEnum|string $to,
        public readonly ?Closure $guard = null,
        public readonly ?string $event = null,
    ) {
    }

    public function matches(BackedEnum|string $from, BackedEnum|string $to): bool
    {
        return $this->normalize($this->from) === $this->normalize($from)
            && $this->normalize($this->to) === $this->normalize($to);
    }

    public function isAllowedFor(object $model): bool
    {
        return $this->guard === null || ($this->guard)($model);
    }

    protected function normalize(BackedEnum|string $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }
}
