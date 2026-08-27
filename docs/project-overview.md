# Flowstack — project overview

> The single map of the whole product. Start here, then jump to the
> linked phase docs / architecture notes for depth. **Code is the source
> of truth** — this doc explains *what* exists and *how the pieces fit*;
> when in doubt, read the file it points to. Branch: `runtime-native-l1`.

See also: [README.md](./README.md) (docs index + reading order),
[agent-lifecycle.md](./agent-lifecycle.md) (operator's-eye journey),
[architecture/integration-map.md](./architecture/integration-map.md)
(how every surface links to every other).

---

## 1. What Flowstack is

Flowstack is a **multi-tenant SaaS** that gives a business its own AI
customer-service / lead-gen assistant. A customer signs up, names an
agent, teaches it from a knowledge base, shapes its behaviour, and drops
a one-line `<script>` snippet onto their own website. Visitors chat with
the agent in a floating widget; the agent answers from the KB, qualifies
the visitor, and **captures leads** that land on a kanban board the
customer's team works. Conversations are billed in **credits** that meter
each message against the agent's quality tier.

**End-to-end journey** (operator detail in
[agent-lifecycle.md](./agent-lifecycle.md)):

```
sign up + onboard  →  teach (KB)  →  shape (instructions/greeting)  →
test (/chat)  →  embed (<script>)  →  visitor chats  →  lead captured  →
billed per turn
```

- **Sign up & onboard** (~60s): registration creates a personal team on
  the **Starter** plan (2,500 monthly credits); a one-step wizard names
  the agent and `CreateAgent` writes a row with `status=active`,
  `runtime_mode=native`. The agent is live the instant the row exists —
  **nothing external is provisioned**.
- **Teach** (`/knowledge`): paste text / fetch URL / upload a file
  (PDF/TXT/MD/CSV, 10 MB). Each doc is chunked (~500 tokens, overlap),
  embedded via OpenAI, stored per-agent.
- **Shape** (`/agents/versions`): edit a draft (custom instructions +
  greeting guidance), publish → live on the very next message. No deploy.
- **Test / embed / harvest**: talk to it on `/chat`; copy the widget
  snippet from `/install`; captured leads appear live on `/leads`.

The conversational engine is **Flowstack-owned and native** (the prior
third-party engine was fully removed on 2026-06-11; the phase docs for
it remain as historical record). See [runtime-native.md](./runtime-native.md).

---

## 2. System map

The product is one Laravel 12 app (Inertia + Vue 3 + Vite) plus a sibling
Next.js marketing site. The major subsystems:

| Subsystem | Where | Role |
|---|---|---|
| Native runtime | [`app/Runtime/`](../app/Runtime) | The conversational engine — flow loop, LLM clients, tools, KB |
| Billing & credits | [`app/Billing/`](../app/Billing) | Two-bucket credit meter, plans, packs, ledger |
| Controllers / proxies | `app/Http/Controllers/` | `ChatController`, `EmbedController` bill *around* the engine |
| Dashboard UI | [`resources/js/`](../resources/js) | Inertia/Vue pages (leads, conversations, KB, billing, versions) |
| Public surface | `routes/api.php`, `PublicStatsController` | Anonymous stats + health + embed routes + Stripe webhook |
| Marketing site | `/home/theone/automation-landing` | Next.js; reads `/api/public/stats` |
| Ops / quality | `scripts/hermes.sh`, `scripts/agents/`, `tests/` | Watchdog, audit agents, assurance suites |

### Request flow (visitor → reply → billing/leads)

```mermaid
flowchart TD
  V[Website visitor] -->|loads| W["/widget/{slug}.js (embed loader)"]
  W -->|iframe| E["/embed/{slug} chat page<br/>(Art. 50 disclosure)"]
  E -->|POST launch / interact| EC[EmbedController]
  EC -->|credits guard| CM[CreditMeter.consume]
  EC -->|app Runtime::class| RT[AgentRuntime]
  RT --> FE[FlowExecutor]
  FE -->|publishedTier → provider| RTR[LlmRouter]
  RTR --> AC[AnthropicClient]
  RTR --> OC[OpenAiClient]
  RTR --> GC[GeminiClient]
  AC & OC & GC -->|complete| LLM[(LLM provider)]
  FE -->|auto-RAG top-k| KB[(KnowledgeStore / kb_chunks)]
  FE -->|tool dispatch| TR[ToolRegistry]
  TR -->|capture_lead| LD[(leads)]
  FE -->|token rollup| RU[(runtime_usage)]
  RT -->|traces| EC
  EC -->|debit 1+replies × tier| CT[(credit_transactions)]
  LD -->|LeadSaved broadcast| UI[/leads kanban]
```

The dashboard `/chat` panel uses the **same** `AgentRuntime` through
`ChatController` (greetings billed there too; embed greetings get a free
daily cap). Cross-surface links are mapped in
[architecture/integration-map.md](./architecture/integration-map.md).

---

## 3. The native runtime

Full doc: [runtime-native.md](./runtime-native.md). Code:
[`app/Runtime/`](../app/Runtime).

### Engine architecture

Controllers depend only on the `Runtime` contract
([`app/Runtime/Contracts/Runtime.php`](../app/Runtime/Contracts/Runtime.php)
— `launch / sendText / streamText / endSession / health`).
`AgentRuntime` is bound to it as the only implementation; the interface is
kept as the seam for any future engine (proven replaceable by actually
deleting the legacy engine).

Composition (`AgentRuntime`):

- **`SessionManager`** — per-visitor state in `runtime_sessions`
  (flow state + variables + canonical LLM history).
- **`FlowExecutor`** — one call = one visitor turn:
  `resolve state → assemble system prompt (+ auto-RAG) → LLM loop
  (complete → dispatch tools → feed results back, capped) → apply state
  transition → persist → return traces`. Transitions are owned **here**
  (state `onToolSuccess` map / `autoNext`); tools never write `flow_state`.
- **`LeadCaptureFlow`** — the flow every agent runs today
  (greeting → discovery → wrapup → ended). Per-agent flow selection is a
  column away.
- **Tools** (`ToolRegistry`): `capture_lead · query_kb · set_variable ·
  request_handoff · end_session`. Lead capture happens **inside** the
  engine (→ `leads` table + `LeadSaved` broadcast).

Safety rails (config/runtime.php `safety`): `max_tool_calls_per_turn`
(default 10, runaway-loop guard), `max_turns_per_session` (default 100),
`free_greetings_per_day` (default 500, abuse cap on free embed greetings).

### The `LlmClient` contract + per-provider translation

[`app/Runtime/LLM/LlmClient.php`](../app/Runtime/LLM/LlmClient.php) is a
provider-agnostic chat-completion interface (`complete(system, messages,
tools, model, maxTokens)`). The **canonical** message format everywhere
in the runtime (session history, FlowExecutor, tools) is
**Anthropic-shaped** (`{role, content}` with `text` / `tool_use` /
`tool_result` blocks). `LlmRouter` resolves the client for a tier's
provider; `OpenAiClient` / `GeminiClient` translate canonical → their wire
format outbound and back to canonical `contentBlocks` inbound — so an
agent can switch provider **mid-conversation** and keep its history
replayable. (Note: [runtime-native.md](./runtime-native.md)'s architecture
diagram predates the multi-provider split and still names only
`AnthropicClient`; the three-client router in `app/Runtime/LLM/` and
`config/runtime.php` are authoritative.)

### The 5 quality tiers

Customers pick a **tier** per agent on the Versions page — never a raw
model name. The tier couples a model to a credit price so **margin
survives by construction**. Values verified 2026-06-11 against official
provider pages (see [operations/pricing-audit.md](./operations/pricing-audit.md));
`pricing_per_mtok` feeds **only** the `runtime:costs` margin report, never
billing.

| Tier | Provider | Model (env-overridable) | Credits/msg | $/MTok in → out |
|---|---|---|---|---|
| Claude Haiku | Anthropic | `claude-haiku-4-5-20251001` | 1 | $1.00 / $5.00 |
| Claude Sonnet | Anthropic | `claude-sonnet-4-6` | 3 | $3.00 / $15.00 |
| Claude Opus | Anthropic | `claude-opus-4-8` | 10 | $5.00 / $25.00 |
| ChatGPT | OpenAI | `gpt-5.1` | 3 | $1.25 / $10.00 |
| Gemini | Google | `gemini-2.5-flash` | 1 | $0.30 / $2.50 |

Tiers whose provider key is missing are greyed out in the picker and
rejected by validation (`LlmRouter::providerAvailable`). Legacy keys
`standard`/`enhanced` alias to haiku/sonnet so pre-lineup published rows
keep working. The tier rides the published config (draft → publish →
rollback). Per-turn token usage accumulates in
`runtime_sessions.variables` (`_tokens_in`/`_tokens_out`) and is rolled up
durably into `runtime_usage` per tier for cost observability.

### How a turn executes

`FlowExecutor::execute()` resolves the published tier
(`AgentConfigVersion::publishedTier`) → model + provider client, builds
the system prompt (identity guardrails + operator instructions + state
objective + remembered visitor facts + auto-RAG top-3 KB chunks), then
loops `complete → dispatch tools → append tool results` until the model
stops requesting tools or the per-turn cap trips. RAG failures (no
embedding key, empty KB, provider down) degrade to no-context — the turn
still completes. The system prompt hard-codes the AI-disclosure guardrail
("never claim to be human… offer a human handoff").

---

## 4. Billing & credits

Code: [`app/Billing/`](../app/Billing). Audit + economics:
[operations/pricing-audit.md](./operations/pricing-audit.md).

### Two-bucket model

`CreditMeter` ([`app/Billing/CreditMeter.php`](../app/Billing/CreditMeter.php))
is the single entry point for all grants and consumption. Every team has
**two balances**:

- **`credit_balance`** — the monthly allowance. **Hard-reset** at renewal
  (no rollover): `grantMonthlyRenewal()` records the wiped leftover as a
  negative `expire_monthly` ledger row, then sets the new grant.
- **`topup_balance`** — purchased top-up credits. **Roll over** across
  renewals until spent (policy 2026-06-12).

`consume()` drains **monthly first, then top-up** (paid credits are last
to go), inside a `lockForUpdate` transaction so concurrent turns can't
over-spend. It writes a `credit_transactions` audit row for every
mutation; burn-alert thresholds (50/80/95%, plus a `100` out-of-credits
flag) fire once each per period via `EvaluateCreditAlerts`, notifying the
team owner.

### Multipliers, greeting cap, plans/packs

- **Multiplier** = the tier's `credits_per_message`. Controllers bill
  `(1 + replies) × tier` per turn — same math on dashboard and embed
  (the 2026-06-11 audit fixed embed under-billing at 1×).
- **Greeting cap**: embed `launch()` greetings are free up to
  `free_greetings_per_day` (default 500) per team/day; beyond that they
  debit like any turn. The single, capped exception to "every LLM-calling
  endpoint debits".
- **Plans** ([`app/Billing/Plan.php`](../app/Billing/Plan.php)): Free (€0,
  1 agent, 250 credits, no top-ups), Starter (€9/mo, 1 agent, 2,500 credits),
  Growth (€19/mo, ≤5 agents, 10,000 credits), Operator (€39/mo, ≤5 agents,
  25,000 credits), all paid tiers top-up enabled; Custom (project-based, 0
  auto-grant). Repriced 2026-08-27. Enum cases `free`/`pro`/`business` keep
  their original values for column compatibility (`pro` is labelled
  "Operator"); `starter`/`growth` are new. No time-limited trial (product decision).
- **Top-up packs** ([`app/Billing/TopUpPack.php`](../app/Billing/TopUpPack.php)):
  Small $29/1,000, Medium $119/5,000, Large $399/20,000.

### Ledger integrity, reconciliation, margin invariants

- **`credits:reconcile`**
  ([`app/Console/Commands/ReconcileCredits.php`](../app/Console/Commands/ReconcileCredits.php))
  asserts `SUM(credit_transactions) == credit_balance + topup_balance`
  for every team, daily; exits non-zero on drift. This is why
  `expire_monthly` rows exist — without them the audit trail could never
  balance.
- **`runtime:spend-check`**
  ([`app/Console/Commands/RuntimeSpendCheck.php`](../app/Console/Commands/RuntimeSpendCheck.php))
  prices yesterday's `runtime_usage` at provider rates against
  `config/sla.php` `spend.daily_ceiling_usd` (default $25/day) — a runaway
  / abuse tripwire.
- **Standing invariants** (tested by `BillingInvariantsTest`): every
  top-up pack's $/credit is strictly worse than Operator's
  ($0.01596/credit floor) — the upgrade-pressure + margin-floor
  mechanism; every tier stays margin-positive at HIGH usage on the
  cheapest revenue source; every LLM-calling endpoint debits (free
  greetings excepted). Margins run ~71–96% across tiers/sources.

---

## 5. Public surface & the marketing site

Full contract: [public-surface.md](./public-surface.md). Phase doc:
[phase-14-public-stats.md](./phase-14-public-stats.md). Flows:
[architecture/public-stats-flow.md](./architecture/public-stats-flow.md),
[architecture/landing-sse-pipeline.md](./architecture/landing-sse-pipeline.md).

Anonymous, non-session routes: `GET /api/public/stats` (marketing metrics
+ scarcity, 60/min/IP, 5-min server cache), `GET /api/health` (db+cache
probe, no tenant data), `GET /widget/{slug}.js` + `GET /embed/{slug}` +
`POST /embed/{slug}/{launch,interact}` (active agents only), and
`POST /webhooks/stripe` (signature-guarded, deliberately **not**
IP-throttled so renewal bursts aren't dropped).

**Stats / scarcity contract**: live aggregate counts (teams, active
agents, leads, qualified, messages handled, messages last 24h, time saved
hours) plus operator-curated scarcity fields (`founder_slots_*`,
`next_cohort_label`, `featured_proof`) set via `php artisan platform:set`.
Raw counts are competitive intel when small, so the server also returns
**bucketed `display.*` labels** (`10+`, `100+`, `1k+`, … snap-down with
`+`; `null` below 10) — the landing renders **those**, never the raw
numbers. `featured_proof` is render-as-plain-text only (stored-XSS rule).

**Marketing site** (`/home/theone/automation-landing`, Next.js): consumes
the endpoint via [`src/lib/stats.ts`](/home/theone/automation-landing/src/lib/stats.ts)
— `getPlatformStats()` server-fetches with 5-min ISR;
`fetchPlatformStatsFresh()` (no-store) feeds an SSE broadcaster poll loop
(~5s) for the live-feel landing. A conservative `FALLBACK` object means a
dashboard outage never shows empty trust numbers.

---

## 6. Compliance posture

Structure only — see [legal/README.md](./legal/README.md). **These are
drafting aids, not legal advice; counsel reviews before anything is
published.** The folder holds a publishable trust-page draft, an internal
compliance framework (DPA / ToS skeletons), a privacy-policy draft, redline
corrections for the `source/*.docx` masters, and the honesty ledger.

- **GDPR roles**: Platform = **processor** for customer conversation data;
  **controller** for its own dashboard accounts + billing (the
  privacy-policy artifact).
- **EU AI Act Art. 50**: Platform = provider; **AI disclosure is
  implemented and tested** — embed header banner + dashboard chat copy +
  the engine system prompt forbidding "claim to be human" + a human
  handoff path (`request_handoff`).
- **Publication gate**: [legal/claims-vs-reality.md](./legal/claims-vs-reality.md)
  is the **honesty ledger** — a claim may appear in customer-facing copy
  **only when its row is ✅**. It maps every claim to its implementation
  status (✅ true / 🟡 partial / ❌ must-not-claim) and lists the real
  sub-processors (Anthropic, OpenAI, Google[paid-only], Stripe, Pusher,
  AWS SES, Typesense[off]).

---

## 7. Brand & UI

Design docs: [[design-system]] — the brand reference + rules (tokens, the
two sheets, dark mode, the accent, motifs); [[theme-unification]] — the
phased build history.

**"Two sheets, one ink."** One funnel (landing → register → dashboard →
embed) historically had three visual identities. The fix shares one token
set across all three surfaces, inverting only the page colour:

> **Landing = black sheet. App = white sheet. Same ink.** Same Inter +
> JetBrains Mono, same `--radius: 0`, same blueprint motifs — front and
> back of one printed drawing.

The landing's `globals.css` (Tailwind v4, shadcn `base-nova`) is the token
source of truth; `branding/tokens.css` holds **both** palettes
(`.sheet-black` / `.sheet-white`); the dashboard vendors a byte-identical
copy. **Phases 1 + 2 are built** (2026-06-12): tokens bridged, embed
widget + chat iframe + auth surface + ~67 Vue dashboard files swept
on-brand, and the hardcoded `#6366f1` indigo removed from
`EmbedController`. Doctrine recorded there: **mono is chrome discipline,
not data discipline** — semantic status hues (green/amber/red/blue) stay
because in an ops dashboard color *is* data. **Phase 3** (Tailwind v4
unification so both repos consume one `@theme` verbatim) is deferred and
decoupled.

---

## 8. Quality & ops

### Hermes watchdog (`scripts/hermes.sh`)

Local CI mirror — 9 checks, no LLM, free: **vendor present, Pint (style),
PHPStan + dead-code (baseline-tracked), PHPUnit suite, config:cache +
route:list sanity, migration status, composer security audit, composer
validate, frontend build + pnpm audit** (last two skipped with `--fast`).
Writes `data/hermes_findings.json`; exit 1 on FAIL. Operator commands +
the post-2026-06-15 Anthropic credit-pool notes live in
[hermes/README.md](./hermes/README.md).

### Scheduled audit agents + crons (`routes/console.php`)

No-LLM bash collectors under `scripts/agents/`, scheduled daily/periodic:
`audit_sentinel.sh` (security/risk: CVEs, secrets, debug routes, throttle
gaps — daily 6:00), `update_inspector.sh` (outdated deps — weekly),
`system_check.sh` (runtime health — every 6h). Plus the financial/runtime
crons: `runtime:prune-sessions` (daily), `credits:grant-renewals` (daily —
annual-cycle grants + webhook self-heal), `credits:reconcile` (daily 5:30),
`runtime:spend-check` (daily 5:45). Heavier agent jobs (`/hermes-fleet`,
`/hermes-lifecycle`) run on-demand inside interactive Claude Code sessions.

### CI (`.github/workflows/ci.yml`)

Two jobs. **quality**: gitleaks (history secret scan) → PHP 8.3 →
`composer validate --strict` + `composer audit` → Pint → PHPStan →
`php artisan test` (SQLite `:memory:`, not parallel) → boot checks
(config:cache, route:list, fresh migrate). **frontend**: pnpm install →
`pnpm audit --prod` → `pnpm run build`.

### Assurance suites (`phpunit.xml`, one line each)

The strategy spec is `PROJECT_ASSURANCE_STRATEGY.md` (categories A–I).
Suites beyond `Unit`/`Feature`:

| Suite | Asserts |
|---|---|
| `tests/Wiring` | Services/contracts are bound and resolvable as intended |
| `tests/Integrity` | Data invariants — e.g. the credit ledger sum-consistency |
| `tests/Security` | Headers, throttles, log redaction, embed iframe allowances |
| `tests/Contracts` | External-facing contracts (public stats shape, webhooks) |
| `tests/Snapshots` | Pinned HTML/JSON fixtures (embed widget, dialog paths) |
| `tests/Performance` | CI budgets — dashboard render + chat-turn pipeline (`config/sla.php`) |

---

## 9. Current state & what's next

**Shipped** (phase docs are immutable history — see
[README.md](./README.md) index): foundation (Laravel/Jetstream/Inertia),
realtime (Pusher/Echo), leads kanban + delegation, conversation storage +
search, knowledge base, multitenancy + lifecycle, public stats, the native
runtime + the 5-tier multi-provider engine, and the brand unification
(Phases 1–2). Per the launch checklist: code complete, **382 tests passing,
Hermes PASS**.

**The standing blocker** (the only thing between here and a first real
pilot): **provider accounts are unfunded.** Agents create fine, but chat
returns 503 until the platform keys are set —
[operations/launch-checklist.md](./operations/launch-checklist.md) §1
(BLOCKING) needs `ANTHROPIC_API_KEY` + `OPENAI_API_KEY` (and optionally a
**paid** `GEMINI_API_KEY` — free Gemini keys train on customer data). Each
provider needs roughly ~$5 of funding before the first live chat. Stripe
TEST mode (§2) is the other BLOCKING setup item; Pusher and SES degrade
gracefully.

**Deferred by decision** (honest, concrete):

- **Tailwind v4 unification** — dashboard on 3.4.x, landing on v4; Phase 3
  decoupled from Phases 1–2 ([theme-unification.md](./theme-unification.md)).
- **k6 load tests / formal performance targets** — `config/sla.php` carries
  PLACEHOLDER targets + generous CI budgets; real load testing is unbuilt.
- **Mutation testing** — not wired (PHPStan baseline + the assurance suites
  are the current floor).
- **LLM-as-judge transcript evals** — no automated transcript quality
  scoring; the KB Q&A box is the only retrieval check.
- Token-level SSE streaming (currently stage-level), pgvector for KB
  embeddings (JSON + in-process cosine to ~10k chunks/agent), per-agent
  flow selection — all noted in [runtime-native.md](./runtime-native.md).
- No general action audit log (only the credit ledger), no formal DSR
  intake flow, no transcript redaction — tracked in
  [legal/claims-vs-reality.md](./legal/claims-vs-reality.md).

---

## 10. Repo geography

| Path | What lives here |
|---|---|
| [`app/Runtime/`](../app/Runtime) | The native engine: `AgentRuntime`, `Flow/` (FlowExecutor, LeadCaptureFlow), `LLM/` (LlmClient + Anthropic/OpenAi/Gemini + LlmRouter), `Tools/`, `Session/`, `Contracts/` |
| [`app/Billing/`](../app/Billing) | `CreditMeter`, `Plan`, `TopUpPack`, `BillingCycle`, alert evaluation |
| `app/Http/Controllers/` | Chat/Embed proxies (bill around the engine), `PublicStatsController`, billing, KB, versions |
| `app/Console/Commands/` | `ReconcileCredits`, `RuntimeSpendCheck`, renewals, prune, `PlatformSet`, `runtime:costs` |
| `config/` | `runtime.php` (tiers, safety, RAG), `sla.php` (budgets + spend ceiling), billing/stripe price maps |
| [`resources/js/`](../resources/js) | Inertia/Vue pages + components (the dashboard interior) |
| `routes/` | `web.php`, `api.php` (public surface), `console.php` (the schedule) |
| [`docs/`](.) | This file, the docs index, phase docs, `architecture/`, `legal/`, `operations/`, `hermes/` |
| `scripts/` | `hermes.sh` watchdog + `agents/` no-LLM audit collectors |
| `tests/` | `Unit`, `Feature`, `Wiring`, `Integrity`, `Security`, `Contracts`, `Snapshots`, `Performance` |
| `/home/theone/automation-landing` | Sibling Next.js marketing site — reads `/api/public/stats`, shares brand tokens (`branding/tokens.css`) |
