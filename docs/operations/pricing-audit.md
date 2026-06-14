# Pricing audit — 2026-06-11

Four-agent audit: three provider-pricing verifications against official
pages + one internal margin trace. Re-run this audit whenever a tier
model env is bumped or plans/packs are repriced.

## Provider pricing — VERIFIED ✅

| Tier | Model ID | $/MTok in/out | Verified against |
|---|---|---|---|
| Claude Haiku | `claude-haiku-4-5-20251001` | $1 / $5 | platform.claude.com docs (models + pricing) |
| Claude Sonnet | `claude-sonnet-4-6` | $3 / $15 | same — dateless ID is the pinned snapshot format for 4.6+ |
| Claude Opus | `claude-opus-4-8` | $5 / $25 | same |
| ChatGPT | `gpt-5.1` | $1.25 / $10 | developers.openai.com model page |
| Gemini | `gemini-2.5-flash` | $0.30 / $2.50 | ai.google.dev pricing + models |

Watchlist (no action required today):
- **OpenAI**: `gpt-5.1` is valid but off the headline pricing page; the
  5.4/5.5 lineup is current (`gpt-5.4-mini` $0.75/$4.50 is the natural
  cheaper swap; `gpt-5.5` $5/$30 the flagship). The Aug-2026 sunset hits
  only `-chat-latest` aliases, not our pinned ID. `gpt-5.1-mini` does
  NOT exist — never configure it.
- **Google**: Gemini 3.x exists (`gemini-3.1-flash-lite` $0.25/$1.50 is
  newer AND cheaper; `gemini-3.5-flash` $1.50/$9 the quality jump).
  2.0-line was shut down — plan an eventual 2.5 migration. Free-tier
  Gemini keys train on customer data; production requires a PAID key.
- **Anthropic**: `claude-fable-5` ($10/$50, 1M ctx) exists as a possible
  above-Opus tier. Dated Claude 4.0 snapshots retire 2026-06-15 (not
  used here).

## Credit economics (post-fix)

Revenue per credit:
| Source | $/credit |
|---|---|
| Starter $99 / 2,500 | $0.0396 |
| Operator $399 / 25,000 | $0.01596 ← the floor every pack must stay above |
| Small pack $29 / 1,000 | $0.0290 |
| Medium pack $119 / 5,000 | $0.0238 |
| Large pack $399 / 20,000 | $0.0200 |

Margins at MID usage (2-call tool turn ≈ 6.4k in / 600 out), embed
billing = (1 + replies) × multiplier ≈ 2 × multiplier per turn:

| Tier (cr/msg) | Starter | Operator | Large pack |
|---|---|---|---|
| Haiku (1) | 88% | 71% | 76% |
| Sonnet (3) | 88% | 71% | 76% |
| Opus (10) | 94% | 85% | 88% |
| ChatGPT (3) | 94% | 85% | 88% |
| Gemini (1) | 96% | 89% | 91% |

## What the audit found and fixed (same day)

1. **Top-up packs were margin-negative** ($10/1k, $39/5k, $129/20k —
   Large at $0.00645/cr made Haiku/Sonnet −46% at MID). Repriced to
   $29/$119/$399; invariant (every pack worse than Operator $/credit)
   restored and now ASSERTED by BillingInvariantsTest.
2. **Embed billed half**: interact charged 1×multiplier ignoring
   replies while the Billing page documented per-reply billing. Now
   bills (1 + replies) × multiplier post-reply, same basis as dashboard.
3. **KB Q&A page ran unbilled LLM calls** (worked at zero balance,
   ~$300/day exposure per hostile authed user). Now debits the tier
   multiplier per question.
4. **Annual subscriptions would have granted credits once a year** (no
   renewal scheduler existed). `credits:grant-renewals` runs daily:
   active paid teams >32 days since last grant get their monthly
   allotment — covers annual cycles AND self-heals missed webhooks.
5. **runtime:costs counted $99 phantom revenue** for never-paid teams
   (plan defaults at signup). Revenue now requires an active
   subscription.
6. Stale copy fixed: Billing page ("1 credit = 1 message", "Operator
   gets the cheapest per-credit rate" — both false), TopUpPack docblock
   (pre-rebrand math), Plan.php, EmbedController comments,
   agent-lifecycle.md.

## Standing invariants (tested)

- Every top-up pack's $/credit > Operator monthly $/credit.
- Every tier is margin-positive at HIGH usage (8k in / 800 out × 2
  calls) on the cheapest revenue source.
- Every LLM-calling endpoint debits credits (chat, embed interact,
  over-cap greetings, KB query) — free greetings are the single,
  capped exception.
