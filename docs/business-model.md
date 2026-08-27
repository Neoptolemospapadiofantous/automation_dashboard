---
type: reference
status: current
tags: [business, pricing, billing, commercial]
date: 2026-06-13
---

# Business model

The commercial model **as implemented in code**. Code is the source of
truth; where this doc describes something not yet built it is labelled
**(assumption — not implemented)**. Companion docs: [[project-overview]] §4
(billing & credits), [[operations/pricing-audit]] (verified provider rates +
margin matrix).

> The older [operations/economics.md](./operations/economics.md) models a
> removed third-party engine and is flagged historical — only its still-true
> mechanics (plan prices, Stripe fees) carry over.

## 1. What we sell

An **AI assistant SaaS, billed in credits**. A customer (a *team*) configures
one or more conversational agents, picks a model quality tier per agent, and
**embeds the widget on their own site** (`GET /widget/{slug}.js` →
`POST /embed/{slug}/{launch,interact}`). Every conversation turn meters
credits against the team's balance.

Plans live in [`app/Billing/Plan.php`](../app/Billing/Plan.php) (hand-rolled
in code — changing pricing is a deploy event, not a runtime tweak). Enum cases
stay `free`/`pro`/`business` for `teams.plan` column compatibility; the
customer-facing labels are rebranded:

| Plan (label) | Price | Max agents | Monthly credits | Top-ups |
|---|---|---|---|---|
| **Free** (`free`) | €0/mo | 1 | 250 | no |
| **Starter** (`starter`) | €9/mo | 1 | 2,500 | yes |
| **Growth** (`growth`) | €19/mo | 5 | 10,000 | yes |
| **Operator** (`pro`) | €39/mo | 5 | 25,000 | yes |
| **Custom** (`business`) | scoped 4–6 wk build | unlimited | 0 (negotiated, granted manually) | out-of-band |

Repriced 2026-08-27 (was Starter €99 / Operator €399, no free tier) to undercut
a category whose modal entry price is €19–45 and where every competitor ships a
free tier. Founder ceiling: the top plan must stay **under $49 USD** — €39 clears
that at any EUR/USD rate up to 1.25. `free`/`pro`/`business` keep their original column values;
`starter`/`growth` are new. There is **no time-limited trial** — the permanent
Free tier is the way in (product decision 2026-06-09 reversed 2026-08-27);
cancel anytime via the Stripe Billing Portal. Despite
the `free` enum case, Starter is a *paid* $99 plan (`priceUsd()` returns 99).

## 2. The credit mechanic

[`CreditMeter`](../app/Billing/CreditMeter.php) is the single entry point for
all grants and consumption. Each team has **two credit buckets**:

- **`credit_balance`** — the monthly allowance. **Hard-reset** at renewal
  (no rollover). `grantMonthlyRenewal()` records the wiped leftover as a
  negative `expire_monthly` ledger row, then sets the new grant.
- **`topup_balance`** — purchased top-up credits. **Roll over** across
  renewals until spent (policy 2026-06-12).

`consume()` drains **monthly first, then top-up** (paid credits last to go),
inside a `lockForUpdate` transaction so concurrent turns can't over-spend, and
writes a `credit_transactions` audit row for every mutation.

**What a credit "is":** the unit of metered conversation. It is *not* one
message — billing is multiplier-weighted. Per turn:

```
debit = (1 + replies) × tier multiplier
```

(`1` for the user message + 1 per agent reply, times the agent's tier cost).
Same math on the dashboard and the embed widget — see
[`EmbedController`](../app/Http/Controllers/EmbedController.php) `interact()`.

**The 5 quality tiers** ([`config/runtime.php`](../config/runtime.php)
`tiers.*`) — customers pick one per agent; credits scale with model power:

| Tier | Model | Credits/msg |
|---|---|---|
| Haiku | `claude-haiku-4-5` | 1 |
| Gemini | `gemini-2.5-flash` | 1 |
| Sonnet | `claude-sonnet-4-6` | 3 |
| ChatGPT | `gpt-5.1` | 3 |
| Opus | `claude-opus-4-8` | 10 |

**Greeting free-cap:** embed `launch()` greetings are free up to
`runtime.safety.free_greetings_per_day` (default 500) per team/day; beyond
that a launch debits the tier multiplier like any turn. This is the single,
capped exception to "every LLM-calling endpoint debits credits."

**Burn alerts:** [`EvaluateCreditAlerts`](../app/Billing/EvaluateCreditAlerts.php)
fires owner notifications once per period at 50/80/95% used, plus a `100`
out-of-credits flag. Both buckets count toward runway; renewal/top-up clears
the flags.

## 3. Top-up packs

One-off credit purchases, available on Starter + Operator
([`Plan::allowsTopUps()`](../app/Billing/Plan.php)). They land in the
rollover `topup_balance` bucket. Catalog
([`app/Billing/TopUpPack.php`](../app/Billing/TopUpPack.php)):

| Pack | Price | Credits | $/credit |
|---|---|---|---|
| Small | $29 | 1,000 | $0.0290 |
| Medium | $119 | 5,000 | $0.0238 |
| Large | $399 | 20,000 | $0.0200 |

**Invariant:** every pack's $/credit is *strictly worse* than Operator's
monthly rate ($399 / 25,000 = **$0.01596/credit**, the floor). That is the
upgrade-pressure mechanism (heavy top-uppers save by upgrading) *and* the
margin floor — a pack priced below it can turn tiers margin-negative (the
2026-06-11 audit caught exactly that).

## 4. Margin model

Customers pay **credits**; we pay providers in **tokens**. Margin survives
**by construction**: the tier multiplier is calibrated so a smarter (more
expensive) model costs proportionally more credits. `pricing_per_mtok` in
`config/runtime.php` feeds *only* the ops margin report — never billing.

Tier → credits/msg → provider rate → rough margin (at MID usage, on the
cheapest revenue source = Operator $0.01596/credit; per
[[operations/pricing-audit]]):

| Tier | Cr/msg | $/MTok in/out | Margin (Operator) |
|---|---|---|---|
| Haiku | 1 | $1 / $5 | 71% |
| Sonnet | 3 | $3 / $15 | 71% |
| Opus | 10 | $5 / $25 | 85% |
| ChatGPT | 3 | $1.25 / $10 | 85% |
| Gemini | 1 | $0.30 / $2.50 | 89% |

Margins run ~71–96% across all tier/revenue combinations. The rates were
**VERIFIED 2026-06-11** against official provider pages.

**Tested invariants** (`tests/Feature/BillingInvariantsTest.php`):
- Every top-up pack's $/credit > Operator's $/credit (margin floor).
- Every tier stays margin-positive at HIGH usage (8k in / 800 out × 2 calls)
  on the cheapest revenue source.
- Every LLM-calling endpoint debits credits (chat, embed interact, over-cap
  greetings, KB query) — free greetings the only exception.

**Margin report:** `php artisan runtime:costs [--month=YYYY-MM]`
([`RuntimeCosts.php`](../app/Console/Commands/RuntimeCosts.php)) — per-team
token spend (priced from `runtime_usage` rollups × provider rates) vs. plan
revenue. Revenue counts subscriptions only (top-ups excluded to stay
conservative) and only for teams with an **active** subscription (no phantom
revenue from never-paid signups). An ops view — customers never see token
economics.

## 5. How money flows

All real money runs through **Stripe** (test + live keyed via env, see
[`config/billing.php`](../config/billing.php)). Stripe price IDs per
plan/cycle/pack are env-mapped so each environment points at its own products.

- **Subscribe:** `POST /subscribe/{plan}?cycle=` →
  [`SubscribeController`](../app/Http/Controllers/SubscribeController.php)
  creates a Stripe **Checkout** session and redirects the browser. Activation
  happens via webhook, not synchronously.
- **Buy a top-up pack:** `BillingController@topup` →
  [`BillingController`](../app/Http/Controllers/BillingController.php) creates a
  one-off Checkout session; the grant happens on webhook.
- **Manage / cancel:** `BillingController@portal` redirects to Stripe's hosted
  **Customer Portal** (cancel, update card, download invoices). Cancellation
  returns via `customer.subscription.deleted`.

**Webhook** ([`StripeWebhookController`](../app/Http/Controllers/StripeWebhookController.php),
signature-guarded, deliberately not IP-throttled) — all handlers idempotent:

| Event | Effect |
|---|---|
| `checkout.session.completed` (mode=subscription) | set `plan`, grant monthly credits |
| `checkout.session.completed` (mode=payment) | grant top-up credits (deduped by session id) |
| `invoice.paid` (recurring) | monthly renewal grant; cache period end |
| `invoice.payment_failed` | mark `past_due` |
| `customer.subscription.updated` | mirror status |
| `customer.subscription.deleted` | downgrade to Starter; **keep existing credits** (no clawback) |

A daily `credits:grant-renewals` job self-heals missed renewal webhooks and
covers annual cycles (active paid teams >32 days since last grant).

## 6. Unit economics

- **Cost driver = tokens.** Every turn the FlowExecutor writes a
  `runtime_usage` rollup (tier, turns, tokens in/out). Cost = tokens ×
  `pricing_per_mtok`. Real driver of cost is conversation length × model tier,
  not headcount.
- **Revenue driver = credits sold.** Subscription grants (recurring) +
  top-up packs (one-off). Price-per-credit is fixed per plan; margin is
  locked by the tier multipliers above.
- **Daily spend ceiling guard:** `php artisan runtime:spend-check`
  ([`RuntimeSpendCheck.php`](../app/Console/Commands/RuntimeSpendCheck.php),
  scheduled) prices yesterday's platform-wide `runtime_usage` and **fails** if
  it crosses `config/sla.php` `spend.daily_ceiling_usd` (default **$25/day**)
  — a runaway-agent / abuse tripwire. `runtime:costs` then names the team.
- **Per-conversation rails** (`runtime.safety`): `max_tool_calls_per_turn`
  (10) and `max_turns_per_session` (100) cap how much a single conversation
  can burn.
- **Ledger integrity:** `credits:reconcile` asserts
  `SUM(credit_transactions) == credit_balance + topup_balance` per team daily;
  exits non-zero on drift.

## 7. Assumptions / not-yet-built

- **Annual billing** — `BillingCycle::Annual` exists with a hard-coded **17%**
  ("2 months free") discount and separate annual Stripe Price IDs, but the
  annual prices are env-gated; the UI hides the annual toggle when unset.
  Live only once `STRIPE_PRICE_*_ANNUAL` are configured. *(assumption — not
  fully wired in production.)*
- **Custom / enterprise tier** — `business` plan grants 0 credits and is
  project-based; credits are negotiated and **granted manually by ops**. No
  self-serve flow, no published pricing. *(assumption — not implemented as a
  billable SaaS tier.)*
- **CAC / LTV / customer-mix forecasts** — no acquisition-cost or
  lifetime-value figures are grounded anywhere in code. The historical
  customer-count projections in `operations/economics.md` model the *removed*
  legacy engine. *(assumption — not implemented / not modelled.)*
- **Above-Opus / model swaps** — `claude-fable-5`, `gpt-5.5`,
  `gemini-3.x` appear on the pricing-audit watchlist as candidate tier
  upgrades; none are configured. *(assumption — not implemented.)*
