<?php

namespace App\Lifecycle;

use App\Events\Domain\StateChanged;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Base class for a domain entity's state machine.
 *
 * Subclasses declare allowed transitions via transitions() and the model's
 * status attribute name via stateAttribute() (defaults to 'status').
 *
 * Lifecycle:
 *   $machine = new LeadStateMachine($lead);
 *   $machine->transitionTo(LeadStatus::Qualified, by: $user);
 *
 * The transition is atomic: validation → guard → persist → fire StateChanged
 * → fire any transition-specific event, all inside a DB transaction. If any
 * step throws, the model's status is unchanged on disk AND in memory.
 */
abstract class StateMachine
{
    public function __construct(protected Model $model) {}

    /**
     * @return array<int, Transition>
     */
    abstract protected function transitions(): array;

    /**
     * The attribute that holds the state. Override if your model uses
     * something other than 'status'.
     */
    protected function stateAttribute(): string
    {
        return 'status';
    }

    /**
     * Current state of the underlying model.
     */
    public function current(): BackedEnum|string|null
    {
        return $this->model->{$this->stateAttribute()};
    }

    /**
     * Whether a move to $to is allowed from the current state, including the
     * guard. Used by UI code to decide if a button should be enabled.
     */
    public function canTransitionTo(BackedEnum|string $to): bool
    {
        $current = $this->current();
        if ($current === null) {
            return false;
        }

        $transition = $this->findTransition($current, $to);

        return $transition !== null && $transition->isAllowedFor($this->model);
    }

    /**
     * Move to $to. Throws if the transition isn't defined or its guard fails.
     * Returns the (still in-memory) model so callers can chain.
     *
     * @param  array<string, mixed>  $context  Free-form payload included in the StateChanged event for listeners.
     */
    public function transitionTo(BackedEnum|string $to, array $context = []): Model
    {
        $from = $this->current();
        if ($from === null) {
            throw new InvalidTransition($this->model, '(none)', $to, 'no current state');
        }

        $transition = $this->findTransition($from, $to);
        if ($transition === null) {
            throw new InvalidTransition($this->model, $from, $to, 'transition not declared');
        }

        if (! $transition->isAllowedFor($this->model)) {
            throw new InvalidTransition($this->model, $from, $to, 'blocked by guard');
        }

        DB::transaction(function () use ($to, $from, $transition, $context) {
            $this->model->{$this->stateAttribute()} = $to;
            $this->model->save();

            event(new StateChanged($this->model, $from, $to, $context));

            if ($transition->event) {
                event(new $transition->event($this->model, $from, $to, $context));
            }
        });

        return $this->model;
    }

    protected function findTransition(BackedEnum|string $from, BackedEnum|string $to): ?Transition
    {
        foreach ($this->transitions() as $transition) {
            if ($transition->matches($from, $to)) {
                return $transition;
            }
        }

        return null;
    }
}
