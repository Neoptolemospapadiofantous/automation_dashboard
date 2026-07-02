# Phase 16 — Automations (agent → n8n webhook tool-call)

**The wedge.** Until now the agent could only *talk* (answer from its
knowledge base) and *capture* (write a lead). This phase gives it *hands*: the
ability to call an operator-configured **n8n** workflow mid-conversation —
look up an order, create a ticket, sync a CRM, kick off any automation — and
weave the result back into its reply.

This is the feature that moves Flowstack from "a chatbot" into "an AI agent +
automation platform" (the GoHighLevel category, not the Chatbase category —
see [competitors.md](./competitors.md)). Chat = the brain, n8n = the hands.

> Status: **shipped end-to-end.** Backend (this doc), the operator Actions UI
> (`998ec50`, gated to Hermes operators in `04e4b58` — automations are a
> managed service, clients only see Activity), the read-only Activity view
> over `automation_runs` (`d8a4113`), redirect rejection in the SSRF guard
> (`625c4ef`), and connection pinning to vetted IPs (`38734d2`) have all
> landed — see [Remaining work](#remaining-work).

---

## Direction

Two directions were on the table:

- **A — chat calls n8n as a tool** (the agent decides, mid-turn, to invoke a
  workflow and uses the result). ← **chosen**
- B — n8n orchestrates the agent (a workflow calls the agent as a step).

Direction A reuses the existing runtime tool framework (a new `Tool`, not new
infrastructure) and keeps the conversation as the driver, which is what makes
the "agent that can *do* things" story land.

## Architecture at a glance

```
visitor turn
  → FlowExecutor assembles the turn
      • injects the agent's "Available automations" catalog into the system prompt
      • offers the call_automation tool (when enabled + the agent has automations)
  → LLM decides to call_automation { action, arguments }
  → CallAutomationTool::execute  (app/Runtime/Tools/CallAutomationTool.php)
  → AutomationDispatcher::dispatch (app/Runtime/Automation/AutomationDispatcher.php)
        1. circuit-breaker gate         (paused endpoint → no fire)
        2. write automation_runs row    (audit, status=pending)
        3. SSRF pre-flight              (bad URL → blocked, NOT billed)
        4. bill via CreditMeter         (out of credits → no fire)
        5a. sync  → AutomationCaller::send → result in the same turn
        5b. async → DispatchAutomationJob on the DB queue → "kicked that off"
  → tool result flows back to the LLM → it finishes the reply
```

One tool (`call_automation`), **not** one tool per action. The model learns
*which* automations exist and *when* to use each from the catalog block in the
system prompt; the tool call just names the action and passes arguments. This
keeps `ToolRegistry`'s static spec model intact (no per-agent dynamic specs in
the hot path) while still being fully per-agent.

## Where things live

| Concern | File |
| --- | --- |
| The tool the LLM calls | `app/Runtime/Tools/CallAutomationTool.php` |
| Orchestration (breaker → audit → guard → bill → fire) | `app/Runtime/Automation/AutomationDispatcher.php` |
| The signed HTTP call | `app/Runtime/Automation/AutomationCaller.php` |
| **SSRF guard** | `app/Runtime/Automation/OutboundGuard.php` |
| HMAC signer | `app/Runtime/Automation/WebhookSigner.php` |
| Action value object + config reader | `AutomationAction.php`, `AutomationCatalog.php` |
| Per-agent circuit breaker | `app/Runtime/Automation/CircuitBreaker.php` |
| Async execution | `app/Jobs/DispatchAutomationJob.php` |
| Audit table model | `app/Models/AutomationRun.php` |
| Prompt catalog + tool offering | `app/Runtime/Flow/FlowExecutor.php` |
| Config | `config/runtime.php` → `automation.*` |
| Tests | `tests/Unit/Runtime/Automation/*`, `tests/Feature/Runtime/AutomationToolTest.php` |

## Configuring an action (the data shape)

Actions ride in the **published** `AgentConfigVersion.config['automations']`
array — so they get the existing draft → publish → rollback lifecycle for
free, and a draft is invisible to the engine. One entry:

```jsonc
{
  "name": "lookup_order",                 // snake_case; the LLM names this
  "description": "Look up an order by its number for the visitor.",  // WHEN to call
  "url": "https://n8n.flowstack.run/webhook/orders",                  // n8n target
  "mode": "sync",                          // "sync" | "async"
  "credit_cost": 3,                        // debited before firing
  "parameters": { "type": "object", "properties": { "id": {"type":"string"} } }
}
```

Malformed entries (missing `name`/`url`) are silently dropped so one typo can't
break the others or the turn.

## Execution modes

- **sync** (request-response): awaits the webhook with a hard timeout
  (`automation.sync_timeout`, default 6s) and feeds the response body straight
  back to the model. For fast lookups.
- **async** (fire-and-forget): bills, queues `DispatchAutomationJob` on the
  existing database queue, and returns immediately ("kicked that off"). For
  slow / side-effecting workflows.

## Security — the part that matters most

This is operator-supplied URL + an outbound HTTP call from the app server, so
SSRF is the headline risk. Defenses, all converging in `OutboundGuard` +
`AutomationCaller`:

1. **Scheme allowlist** — `https` only in production (`http` permitted *only*
   when `allow_private_hosts` is on, i.e. local dev hitting `localhost:5678`).
2. **No embedded credentials** (`user:pass@host`).
3. **DNS resolution + per-address vetting** — every A/AAAA the host resolves to
   must be globally routable. Blocks loopback, RFC1918, link-local (incl. the
   `169.254.169.254` cloud-metadata endpoint), and reserved ranges. Resolving
   here (not just inspecting the literal host) is what defeats a hostname that
   points at a private IP.
4. **HMAC-SHA256 signing** — body signed as `{timestamp}.{rawBody}` with the
   agent's `automation_secret`; headers `X-Flowstack-Timestamp` /
   `X-Flowstack-Signature`. n8n verifies before running, so a leaked webhook
   URL alone can't trigger the workflow.

`automation_secret` is a **new** encrypted column on `agents` (auto-generated
per agent). It is **not** the old Voiceflow `webhook_secret` (that authenticated
*inbound* VF callbacks and was dropped with the Voiceflow schema in
`2026_06_11_100000`). It lives on the agent, not the versioned config, because
a credential is infrastructure — it must not roll back when an operator reverts
a behavior version.

> The TOCTOU DNS-rebinding window between the guard's resolution and the HTTP
> request was closed in `38734d2`: the caller pins the connection to the
> guard-vetted IPs (`CURLOPT_RESOLVE`) so the request can only reach an address
> that passed vetting. Redirect responses are also rejected (`625c4ef`,
> `allow_redirects` off) — an n8n webhook target never legitimately redirects,
> and following one would bypass the guard.

## Billing, idempotency, reliability

- **Bill before firing.** `CreditMeter::consume` runs before any request leaves
  the box; out of credits → tool returns an error, no webhook. A blocked URL
  (operator misconfig) is recorded but **not** billed.
- **Idempotency.** Each invocation gets a unique `idempotency_key` on its
  `automation_runs` row (unique index). The async job reloads the row and
  no-ops if it already reached a terminal state, so a queue retry can't
  double-fire.
- **Retries + circuit breaker.** `DispatchAutomationJob` has `tries=3` with
  backoff. After N consecutive failures for one action the `CircuitBreaker`
  opens (cache-backed, per-agent-per-action) and the dispatcher short-circuits
  — a flapping endpoint pauses instead of burning credits and latency. Bad-URL
  blocks don't trip the breaker (retrying won't help).

## The audit table = the seed of the data layer

Every invocation writes one `automation_runs` row (request id, action, mode,
status, credits charged, http status, duration, trimmed request/response). This
is both the dashboard's automation activity view **and** the seed of the #14
structured data layer (the moat — structured conversation/automation data is
what we sell back to operators).

## Rollout

- Master flag `RUNTIME_AUTOMATION_ENABLED` (default **off**). Off → the tool is
  never offered and no webhook ever fires (kill switch).
- `RUNTIME_AUTOMATION_ALLOW_PRIVATE` — **must stay false in production.** Local
  dev (`docker-compose.local.yml` n8n on `localhost:5678`) sets it true.
- Plan: enable for **team 1** (the live demo) first.

## Remaining work

1. ~~**Operator "Actions" UI**~~ — shipped in `998ec50` (`/agents/actions`,
   `AgentActionsController`), gated to Hermes operators in `04e4b58`.
2. ~~**Automation activity view**~~ — shipped in `d8a4113` (`/agents/activity`,
   `AutomationActivityController` over `automation_runs`).
3. ~~**Connection pinning**~~ — shipped in `38734d2` (requests pinned to the
   guard-validated IPs; redirects rejected in `625c4ef`).
4. **n8n side** — a reusable "verify Flowstack signature" sub-workflow/node.

## Tests

- `tests/Unit/Runtime/Automation/OutboundGuardTest.php` — SSRF matrix (loopback,
  RFC1918, link-local/metadata, literal IPs, scheme, credentials, unresolvable).
- `tests/Unit/Runtime/Automation/WebhookSignerTest.php` — the exact bytes n8n
  verifies.
- `tests/Feature/Runtime/AutomationToolTest.php` — full contract against a
  **fake** n8n endpoint (`Http::fake`, no live calls): registers, bills, signs,
  audits, SSRF-rejects-without-billing, out-of-credits-without-firing, async
  queues, unknown action, feature-flag off.
