# Glossary — Flowstack domain vocabulary

> Terse, code-grounded definitions of the terms that recur across the
> dashboard, the runtime, and the docs. **Code is the source of truth** —
> each entry points at the file it lives in; read that when in doubt.
> Map of how it all fits: [[project-overview]].

Grouped by area, alphabetical within each group.

---

## Tenancy & access

- **Team** — The tenant. A Jetstream team that owns agents, leads,
  conversations, KB, and the two credit balances. Registration creates a
  personal team. Holds `current_agent_id`, `plan`, `credit_balance`,
  `topup_balance`. `app/Models/Team.php`.
- **User** — A person. Belongs to one or more teams via memberships; has a
  `currentTeam`. Owners are stored as `teams.user_id` (Jetstream), not a
  separate column. `app/Models/User.php`.
- **Membership** — The pivot row joining a user to a team and carrying the
  Jetstream role string. `app/Models/Membership.php`.
- **Role** — Per-team capability tier, resolved by `Role::forUser()`:
  **Owner** (the team's `user_id` — money/identity/delete-agent),
  **Admin** (destructive-without-billing: delete leads/KB, create agents),
  **Editor** (create/modify content + manage leads), **Member**
  (read-only + chat). The capability matrix lives as `can*()` methods.
  `app/Authorization/Role.php`; see [[authorization]].
- **Current team / current agent** — The single team a user is acting in,
  and that team's active agent (`Team::currentAgent`). The product is
  "one current agent at a time" — page scopes pass `agent_id` and a null
  agent returns no rows (`scopeForAgent`). `Team::switchAgent()`.

## Product

- **Agent** — One conversational assistant owned by a team (sales bot,
  support bot…). Runs on the native runtime; carries no per-agent
  credentials (platform keys). `status` draft/active/disabled, `slug` for
  public embed URLs, `runtime_mode=native`. `app/Models/Agent.php`.
- **Agent config version** — A saved, versioned snapshot of an agent's
  operator-editable behavior (custom instructions, greeting guidance,
  `model_tier`). Lifecycle **draft → published → archived**; the executor
  injects only the **published** row each turn — drafts are invisible to
  the engine. `app/Models/AgentConfigVersion.php`.
- **Tier** — A quality level picked per agent (never a raw model name)
  that couples a model to a credit price so margin survives by
  construction. The 5: Claude Haiku (1 credit), Claude Sonnet (3), Claude
  Opus (10), ChatGPT (3), Gemini (1). Legacy keys `standard`/`enhanced`
  alias to haiku/sonnet; unknown values degrade to the cheapest.
  `AgentConfigVersion::publishedTier()`, `config/runtime.php` `tiers`.
- **Flow** — A named set of conversation states plus the initial one. The
  only flow today is **LeadCaptureFlow** (greeting → discovery → wrapup →
  ended). `resolve()` degrades unknown state names to the initial state.
  `app/Runtime/Flow/Flow.php`, `LeadCaptureFlow.php`.
- **State** — One node in a flow: a `prompt` appended to the system
  prompt, the `tools` the model may call there, an `onToolSuccess`
  transition map, and an optional `autoNext`. Transitions are owned by the
  FlowExecutor — **tools never write `flow_state`**. `app/Runtime/Flow/State.php`.
- **Tool** — A function the model may invoke mid-turn. The registry holds
  `capture_lead`, `query_kb`, `set_variable`, `request_handoff`,
  `end_session`; the current state decides which are offered. Tool
  failures never crash the turn (returned as an `is_error` tool_result).
  `app/Runtime/Tools/`, `ToolRegistry.php`.
- **Knowledge base (KB)** — Per-agent corpus the agent answers from.
  Documents are chunked and embedded; the executor auto-RAGs the top-k
  chunks into the system prompt each turn. `app/Runtime/Knowledge/`.
- **KB document** — One ingested source (text/URL/file) for an agent;
  stores `raw_content`, `source`, and a `chunk_count`.
  `app/Runtime/Models/KbDocument.php`.
- **KB chunk** — A ~500-token slice of a document with its `embedding`
  (`list<float>`) and `embedding_model`, used for vector search.
  `app/Runtime/Models/KbChunk.php`.

## Conversations

- **Visitor** — An anonymous end-user chatting with the agent, keyed by
  `visitor_id`. Same id threads a Conversation, a RuntimeSession, and a
  captured Lead. (`visitor_id` is the renamed former visitor id column.)
- **Conversation** — A persisted chat thread for the dashboard:
  team/agent/lead/visitor refs, `channel`, `status` (state machine),
  `message_count`, timestamps. `app/Models/Conversation.php`.
- **Message** — One turn within a conversation: `role`, `text`,
  `trace_type`, `payload`, `sequence`. Indexed for keyword search
  (Typesense via Scout) when it carries text. `app/Models/Message.php`.
- **Session (RuntimeSession)** — Per-(agent, visitor) working state
  between turns: `flow_state`, `variables`, and the canonical
  Anthropic-shaped LLM `history`. The engine's memory; distinct from the
  user-facing Conversation. `app/Runtime/Models/RuntimeSession.php`.
- **Transcript** — The replayable record of a conversation (its ordered
  Messages, referenced by `Conversation.transcript_id`). The conversation
  history view and the kanban → transcript cross-link read it.

## Lead pipeline

- **Lead** — A captured prospect: contact fields, `source`, `score`,
  `status` (`LeadStatus` enum), `captured` (raw fields), `assigned_to`.
  Created **inside the engine** by `capture_lead`, which dedupes on
  (team, agent, email). `app/Models/Lead.php`,
  `app/Runtime/Tools/CaptureLeadTool.php`.
- **Lead status** — The pipeline stage enum: **New → Engaging → Qualified
  → Assigned → Won | Lost**. Drives the kanban columns and colors.
  `app/Enums/LeadStatus.php`.
- **Qualified / Won / Lost** — Pipeline milestones. *Qualified*: the agent
  judged the lead a fit. *Won*/*Lost*: terminal outcomes. Each transition
  fires a typed domain event (`LeadQualified`/`LeadWon`/`LeadLost`).
- **Lead assignment / delegation** — Routing a lead to a rep, recorded in
  `lead_assignments` with the `strategy` used. `app/Models/LeadAssignment.php`.
- **Assignment strategy** — How a lead is assigned: **round_robin**,
  **least_loaded**, **manual**, **unassigned**. `app/Enums/AssignmentStrategy.php`.

## Billing

- **Credit** — The unit chat is billed in. Controllers debit
  `(1 + replies) × tier multiplier` per turn. A team's spendable total is
  `credit_balance + topup_balance`. `Team::totalCredits()`.
- **Two buckets** — **Monthly grant** (`credit_balance`) — the plan
  allowance, **hard-reset** at renewal with no rollover. **Top-up**
  (`topup_balance`) — purchased credits that **roll over** until spent.
  `consume()` drains monthly first, then top-up. `app/Billing/CreditMeter.php`.
- **Plan** — Subscription tier (enum cases `free`/`pro`/`business` kept for
  column compatibility, rebranded **Starter** $99 / **Operator** $399 /
  **Custom**). Sets `monthlyCredits()`, `maxAgents()`, `allowsTopUps()`.
  `app/Billing/Plan.php`.
- **Top-up pack** — One-off credit purchase: **Small** $29/1,000,
  **Medium** $119/5,000, **Large** $399/20,000. Every pack's $/credit is
  deliberately worse than Operator's $0.01596 floor (upgrade pressure +
  margin floor). `app/Billing/TopUpPack.php`.
- **Billing cycle** — Subscription cadence, **monthly** or **annual**
  (~17% off). Selects which Stripe price id a plan uses.
  `app/Billing/BillingCycle.php`.
- **Credit transaction / ledger** — The audit row written for every grant
  or consumption (`amount` signed, `reason`); the ledger is ground truth —
  `credits:reconcile` asserts `SUM(transactions) == credit + topup`.
  Reasons: `grant_renewal`, `grant_topup`, `consume_message`,
  `expire_monthly`. `app/Models/CreditTransaction.php`.
- **Greeting cap** — Embed `launch()` greetings are free up to
  `free_greetings_per_day` (default 500) per team/day; beyond that they
  debit like any turn. The one capped exception to "every LLM endpoint
  debits". `config/runtime.php` `safety`.
- **Runtime usage** — Per-day token rollup (team × agent × date × tier)
  for the `runtime:costs` margin report — separate from billing.
  `app/Models/RuntimeUsage.php`.

## Embed

- **Widget** — The floating chat launcher the customer drops on their site
  via a one-line `<script>`. Served from `GET /widget/{slug}.js`.
- **Embed chat page** — The iframe chat surface at `GET /embed/{slug}`;
  `POST /embed/{slug}/{launch,interact}` drive it through `EmbedController`
  (active agents only). `resources/views/embed/chat.blade.php`.
- **Art. 50 disclosure** — The EU AI Act AI-disclosure requirement. The
  agent must reveal it is an AI and offer a human handoff: embed header
  banner + dashboard chat copy + a system-prompt guardrail forbidding
  "claim to be human" + the `request_handoff` tool path.

## Ops

- **Hermes** — The local CI-mirror watchdog (`scripts/hermes.sh`): 9
  no-LLM checks (Pint, PHPStan, PHPUnit, migration status, security audit,
  doc-coverage, frontend build…). Writes `data/hermes_findings.json`,
  exit 1 on FAIL. See `docs/hermes/`.
- **Doc-coverage gate** — A Hermes check (`scripts/doc_coverage.py`): every
  `app/` directory holding PHP must be registered in `docs/coverage.json`
  with a `doc` pointer or an explicit `waived` reason. Adding a subsystem
  fails CI until it's documented or waived.
- **Domain events** — Internal facts dispatched on every lifecycle change
  (`app/Events/Domain/`, e.g. `LeadQualified`, `AgentCreated`). Plain PHP,
  **no broadcasting, intentionally unlistened** — a seam for future audit
  / integrations. See [[domain-events]].
- **Broadcast events** — `ShouldBroadcast` events in `app/Events/` pushed
  over Pusher to drive the live UI (e.g. `LeadSaved` updating the kanban).
  The opposite job to domain events. See [[domain-events]].
