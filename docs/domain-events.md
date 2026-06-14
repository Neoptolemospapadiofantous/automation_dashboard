# Domain events — the audit/extension seam

> Status: **dispatched, intentionally unlistened.** These events fire on every
> meaningful lifecycle change but nothing handles them *yet*. That's by design,
> not a leftover — see [Why no listeners](#why-no-listeners-yet).

The app has **two** event systems that look similar but do opposite jobs:

- **Domain events** (`app/Events/Domain/`) — internal facts: "a lead became
  qualified", "an agent was created". Plain PHP objects, no broadcasting. They
  exist as a *seam* for future audit logs, integrations, and webhooks.
- **Broadcast events** (`app/Events/`) — `ShouldBroadcast` events that push over
  Pusher to drive the live UI. Covered in [[phase-2-realtime]].

This doc is about the first kind, and exists mainly to make one thing explicit:
the domain events are wired up but have no listeners, and that is deliberate.

## The events

All live in [`app/Events/Domain/`](../app/Events/Domain). Every typed event
except `AgentCreated` extends `StateChanged`, so they share its four-argument
constructor: `(Model $model, BackedEnum|string $from, BackedEnum|string $to,
array $context = [])`. None implement `ShouldBroadcast`.

| Event | When fired | Payload |
|---|---|---|
| `StateChanged` | After **every** successful state-machine transition, alongside the typed event below | `model, from, to, context` |
| `LeadStatusChanged` | Any lead transition with no more-specific event (e.g. `new → engaging`) | same |
| `LeadQualified` | Lead → `qualified` | same |
| `LeadAssigned` | Lead → `assigned` | same |
| `LeadWon` | Lead → `won` (terminal) | same |
| `LeadLost` | Lead → `lost` (terminal) | same |
| `AgentActivated` | Agent → `active` (from `draft` or `disabled`) | same |
| `AgentDisabled` | Agent → `disabled` | same |
| `ConversationEnded` | Conversation → `ended` (terminal) | same |
| `AgentCreated` | Once when an `Agent` row is persisted (**not** a transition) | `Agent $agent` |

Note the layering: a lead moving to `qualified` fires **both** `StateChanged`
(generic, for cross-cutting concerns like audit) **and** `LeadQualified`
(typed, for behaviour). A listener subscribes at whichever granularity fits —
see the docblock on [`StateChanged`](../app/Events/Domain/StateChanged.php).

## Where they're fired

Two dispatch paths, nothing else:

1. **State machines** — [`app/Lifecycle/StateMachine::transitionTo()`](../app/Lifecycle/StateMachine.php)
   is the single chokepoint. Inside one DB transaction it validates the move,
   runs the guard, persists, then fires `StateChanged` followed by the
   transition's typed event (if the [`Transition`](../app/Lifecycle/Transition.php)
   declares one). The per-entity transition tables and their events live in
   [`LeadStateMachine`](../app/Lifecycle/LeadStateMachine.php),
   [`AgentStateMachine`](../app/Lifecycle/AgentStateMachine.php), and
   [`ConversationStateMachine`](../app/Lifecycle/ConversationStateMachine.php).

   Callers never `event()` directly — they call `transitionTo()` via the
   [`HasLifecycle`](../app/Lifecycle/HasLifecycle.php) trait. Real call sites:
   - `LeadController::updateStatus` — `$lead->transitionTo($newStatus)` for
     kanban drags ([LeadController.php](../app/Http/Controllers/LeadController.php)).
   - `LeadDelegator` — `$lead->transitionTo(LeadStatus::Assigned)` on delegation
     ([LeadDelegator.php](../app/Services/LeadDelegator.php)).
   - `ConversationRecorder` — `$conversation->transitionTo('ended')` when the
     engine ends a session ([ConversationRecorder.php](../app/Services/ConversationRecorder.php)).

2. **Actions** — `AgentCreated` is the one event fired outside a transition,
   straight from [`CreateAgent::execute()`](../app/Actions/Agents/CreateAgent.php)
   after the row lands (managed agents are born `active`, so there's no
   `draft → active` transition to hang it on — see [[phase-13-multitenancy]]).

## Why no listeners yet

There is **no `EventServiceProvider`, no `$listen` map, and no `Event::listen`
call** that subscribes to any of these. (The lone listener,
[`SendWelcomeEmail`](../app/Listeners/SendWelcomeEmail.php), handles Laravel's
own `Registered` event — not a domain event.) Verify with:

```
grep -rn -E "Domain\\(LeadQualified|AgentCreated|StateChanged)" app/Providers app/Listeners bootstrap
```

This is **intentional, not dead code.** The events are an *extension seam*:
the lifecycle layer already knows the exact moment every meaningful thing
happens, so it announces it. We pay almost nothing to dispatch an unheard
event, and in return future work can hook in **without touching the state
machines or controllers**:

- an **audit log** that records every `StateChanged` to a table,
- **outbound webhooks / CRM sync** on `LeadWon` / `LeadQualified`,
- **integrations** reacting to `AgentCreated`,
- analytics/notification side-effects on `ConversationEnded`.

The docblocks (e.g. "Auto-assignment listens to this" on `LeadQualified`)
describe the *intended* consumers, not existing ones. Treat them as a contract
for whoever wires the listener.

## Domain events vs broadcast events

| | Domain (`app/Events/Domain/`) | Broadcast (`app/Events/`) |
|---|---|---|
| Purpose | internal audit / extension seam | drive the live UI |
| `ShouldBroadcast`? | no | yes |
| Transport | in-process Laravel events | Pusher → browser |
| Listeners today | **none (by design)** | the JS client (Echo) |
| Examples | `LeadQualified`, `StateChanged`, `AgentCreated` | `DashboardTick`, `LeadSaved`, `LeadMessage`, `LeadDeleted` |

The broadcast events are a separate, *fully wired* system: `LeadSaved` /
`LeadMessage` / `LeadDeleted` patch kanban cards in place over the private
`team.{id}` channel, and `DashboardTick` is the heartbeat. They're queued and
fire after-commit. See [[phase-2-realtime]] for that pipeline. Don't conflate
the two: adding a domain-event listener does **not** put anything on the wire.

## Adding a listener

Laravel 11+ auto-discovers listeners by `handle()` signature, so no
registration is needed — just drop a class in `app/Listeners/`:

```php
namespace App\Listeners;

use App\Events\Domain\StateChanged;

class RecordAuditTrail
{
    public function handle(StateChanged $event): void
    {
        // $event->model, $event->from, $event->to, $event->context
    }
}
```

Subscribe to `StateChanged` for everything, or to a typed event
(`LeadWon`, `AgentCreated`, …) for a specific reaction. Implement
`ShouldQueue` if the work shouldn't block the request. Code is the source of
truth — confirm the current payload against
[`app/Events/Domain/`](../app/Events/Domain) before relying on a field.
