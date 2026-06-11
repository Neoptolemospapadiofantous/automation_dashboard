> **HISTORICAL / Voiceflow-legacy.** The native runtime is the default engine since `runtime-native-l1` — see [docs/runtime-native.md](../runtime-native.md). Voiceflow specifics below apply only to legacy `runtime_mode=voiceflow` agents.

---
type: reference
status: active
tags: [operations, business, pricing, voiceflow]
date: 2026-06-09
---

# Flowstack — Unit Economics

The full money math for Flowstack across the three Voiceflow plan stages
(Pro → Business → Enterprise). Numbers reflect what's on
flowstack.com/pricing and creator.voiceflow.com/pricing as of 2026-06-09.
Updated when either changes.

For day-by-day setup, see [[ship-fast-plan]]. This doc only covers
the money.

## Pricing surface

### What we charge customers

| Tier | Price | Agents | Credits/mo |
|---|---|---|---|
| **Starter** | $99/mo | 1 | 2,500 |
| **Operator** | $399/mo | 5 | 25,000 |
| **Custom** | scoped 4-6 week build | bespoke | negotiated |

Per `app/Billing/Plan.php`. The credit allotment translates roughly to
"2,500 / 25,000 conversation messages per month" — a comfortable
allowance for normal usage with top-ups available on Starter + Operator.

### What Voiceflow charges us

| Plan | Price | Project quota | Workspace API key | Editors |
|---|---|---|---|---|
| **Free / Sandbox** | $0/mo | 1 | ❌ | 1 |
| **Pro** | **$60/mo** ($54 annual) | **20** | ✅ | 2 |
| **Business** | $150/mo ($135 annual) | **Unlimited** | ✅ | 5 |
| **Enterprise** | Contact sales (likely $1k-5k+/mo) | Unlimited + maybe partner API | ✅ | Custom |

Plus per-call LLM token cost passed through to our customers via the
credit_balance system. Typical: $5-15/mo per active customer depending
on usage volume + model choice.

### Other fixed costs

| Item | Cost | Notes |
|---|---|---|
| **Railway hosting** | $5-20/mo | Postgres + Laravel app. Scales modestly with traffic. |
| **Cloudflare domain** | ~$1/mo amortized | $10-12/year for `flowstack.com` |
| **Resend (transactional email)** | Free → $20/mo | 100/day free; $20 covers 50k/mo |
| **Stripe** | 2.9% + 30¢ per transaction | No fixed fee; comes off revenue |

## Stage 1 — Voiceflow Pro ($60/mo)

**Capacity ceiling: 20 customers**

### Fixed monthly costs

```
Voiceflow Pro              $60
Railway                    $15  (mid-range estimate)
Domain                     $1
Resend (free tier)         $0
──────────────────────────────
Total fixed                $76/mo
```

### Revenue at customer count N (typical 90/9/1 mix)

Assumptions: 90% Starter ($99), 9% Operator ($399), 1% Custom (skip — project-based, not recurring).

Voiceflow LLM passthrough ≈ $10/customer/month average.

| Customers | Mix (S/O) | Revenue | Voiceflow LLM | Net |
|---|---|---|---|---|
| **1** | 1/0 | $99 | $10 | **+$13** ← break-even at customer #1 |
| 5 | 5/0 | $495 | $50 | +$369 |
| 10 | 9/1 | $1,290 | $100 | +$1,114 |
| 15 | 14/1 | $1,785 | $150 | +$1,559 |
| **18** | 16/2 | $2,382 | $180 | **+$2,126** ← 90% of Pro cap, time to book Business call |
| 20 | 18/2 | $2,580 | $200 | +$2,304 |

### Margin analysis at 20 customers

- Revenue: $2,580/mo
- Fixed costs: $76/mo (3% of revenue)
- Variable costs: $200/mo (8% — Voiceflow LLM passthrough)
- Stripe: ~$75/mo (3% — 2.9% + 30¢ × 20 transactions)
- **Net: ~$2,229/mo (86% gross margin)**
- Annualized: ~$27k profit at 20 customers

### When to upgrade

**Trigger: customer 18.** At 18 you have 2 slots left, and one signup wave
could exhaust the pool. Book the Business upgrade (or Enterprise
conversation) the day you cross 15 so the contract closes by 18.

## Stage 2 — Voiceflow Business ($150/mo)

**Capacity ceiling: unlimited Voiceflow projects (practical ceiling: a few
hundred before your infrastructure becomes the next bottleneck).**

### Fixed monthly costs

```
Voiceflow Business         $150
Railway                    $30   (slight upscale for higher traffic)
Domain                     $1
Resend                     $20   (paid tier, 50k emails)
──────────────────────────────
Total fixed                $201/mo
```

The $90/mo bump over Pro ($60 → $150) is recouped at customer #2 of the
"new" customers after upgrade. If you upgrade with 18 customers in
hand, you're already $1,500+/mo profitable and the upgrade is invisible
in the noise.

### Revenue at customer count N (same mix assumption)

| Customers | Mix (S/O) | Revenue | Voiceflow LLM | Net (post-Stripe) |
|---|---|---|---|---|
| 25 | 22/3 | $3,375 | $250 | +$2,824 |
| 50 | 45/5 | $6,450 | $500 | +$5,545 |
| **100** | 90/10 | **$12,900** | $1,000 | **+$11,254** |
| 200 | 180/20 | $25,800 | $2,000 | +$22,533 |

### Margin analysis at 100 customers

- Revenue: $12,900/mo ($154,800 annualized)
- Fixed costs: $201/mo (1.6% of revenue)
- Voiceflow LLM passthrough: $1,000/mo (7.8%)
- Stripe fees: ~$405/mo (3.1%)
- Customer support time: variable — assume 1 hour/month per 10 customers at $50/hr = $500
- **Net: ~$10,754/mo (83% gross margin)**
- Annualized: ~$129k profit at 100 customers

### When to upgrade

**Trigger: ~ when you need any of:**
- Partner API for fully-automatic provisioning
- Volume pricing on LLM tokens (Voiceflow can negotiate at Enterprise)
- Multi-workspace support (separate billing per customer segment)
- SLA on the runtime API (so you can offer your customers an SLA)
- Custom data retention beyond Business's "forever"

Or pragmatically: when you have 100+ customers AND a specific reason
that Business doesn't fit.

## Stage 3 — Voiceflow Enterprise (Contact sales)

**Capacity: unlimited everything; the relationship becomes the asset.**

### Expected pricing

Voiceflow doesn't publish Enterprise pricing. Comparable SaaS-on-SaaS
arrangements suggest:

- **Floor**: ~$1,000/mo (probably the entry tier)
- **Typical for a serious SaaS partner**: $2,500-5,000/mo
- **Volume LLM discount**: 20-40% off published rates at scale

### What changes for us

```
PoolAllocator::allocate() {
   // Stage 1+2: Read first 'available' row, mark assigned. Pool grows by operator.
   // Stage 3:    Call partner API → mint fresh project → return. Pool grows automatically.
}
```

The codebase doesn't change between Stages 1-2; the upgrade is purely
a contract. Between Stage 2 and Stage 3, ONE method swaps to the
partner API. Everything else (controllers, Vue pages, tests, billing,
lead capture) is unaffected.

### When to consider

**Trigger: any of:**
- Customer 100+ AND signup velocity makes manual project creation a
  daily chore (not just weekly)
- A specific customer or contract requires SLA / dedicated support
- You want co-marketing or referral relationship with Voiceflow
- Multi-channel use cases (voice/SMS/WhatsApp) need volume pricing
- You're starting to lose deals because of LLM cost passthrough

At this stage, the question becomes "is the partner API a feature we
sell, or is it just operational?" If you're approaching IPO-velocity
signups, the partner API is a feature.

## Summary table

| Stage | Voiceflow plan | Customer ceiling | Margin at ceiling | When to advance |
|---|---|---|---|---|
| 1 — Pro | $60/mo | 20 | ~86% | At customer 18 |
| 2 — Business | $150/mo | unlimited (operational cap ~100-300) | ~83% | When partner-API or volume pricing matters |
| 3 — Enterprise | $1k-5k+/mo | unlimited | depends on negotiation | Pre-IPO or strategic |

## Investment runway math

If you had $0 in the bank today and committed to Stage 1:

- Month 0: invest $76 (Voiceflow Pro + Railway + domain)
- Month 1, customer 1: net +$13 — covered
- Month 2, customer 2: net +$102 cumulative
- ...
- Month 12 at 18 customers: ~$25,000 cumulative profit

If signup velocity is slow (1 customer / month): break-even on the
upgrade is immediate. If signup velocity is high (3-5 / month): you'll
hit customer 18 in 4-6 months and be ready for Business by month 6.

## Cross-references

- Pricing config in code: `app/Billing/Plan.php`
- Customer plan tiers documented: `docs/operations/ship-fast-plan.md`
- Voiceflow plan facts: see your current creator.voiceflow.com/profile/billing
- Architecture page (in-app, customer-facing): `/system/architecture`

## When this doc goes stale

Update when any of these change:
- Voiceflow plan pricing (Pro $60, Business $150, Enterprise unknown)
- Our plan pricing on flowstack.com/pricing
- Voiceflow's LLM token costs (passthrough estimate)
- Stripe fee structure (currently 2.9% + 30¢)
- Hosting infrastructure costs
