# Launch checklist — live testing → production

Status as of 2026-06-10: **code complete, environment unconfigured.**
382 tests passing · Hermes watchdog PASS 8/0/0 · all migrations applied.

Work through this top-to-bottom. Each section says what breaks if you skip it.

---

## 0. Pre-flight (2 min)

- [ ] Pick a branch and stay on it during testing:
  - `voiceflow-wrapper-and-hermes-system` — the product surface
  - `runtime-native-l1` — the native engine branch (the only engine; Voiceflow deleted)
- [ ] Unlock pre-verification users. Email verification is now enforced; users
      created before it was enabled have `email_verified_at = null` and will
      hit the verify wall:

  ```bash
  php artisan tinker --execute="\App\Models\User::whereNull('email_verified_at')->update(['email_verified_at' => now()]);"
  ```

---

## 1. Native runtime keys (2 min) — BLOCKING

**Skip symptom:** agent health card red; chat/embed return 503.

New agents run on the Flowstack-owned engine. Two keys are required;
a third is optional:

- [ ] `ANTHROPIC_API_KEY` — console.anthropic.com → API keys (Claude tiers)
- [ ] `OPENAI_API_KEY` — platform.openai.com → API keys (KB embeddings + the ChatGPT tier)
- [ ] `GEMINI_API_KEY` — OPTIONAL, aistudio.google.com/apikey (unlocks the Gemini
      tier; must be a PAID-tier key — free keys train on your customers data)
- [ ] Set monthly spend caps in each provider console (platform-level backstop)
- [ ] `php artisan config:clear`
- [ ] Verify: agent page → health button → `engine: native, ok: true`

---

## 2. Stripe TEST mode (15 min) — BLOCKING

**Skip symptom:** Subscribe / Top-up / Manage-subscription buttons → error.

- [ ] dashboard.stripe.com/test/apikeys → copy `pk_test_*` + `sk_test_*`
- [ ] dashboard.stripe.com/test/products → create:
  - [ ] Starter — recurring $99/mo → copy `price_*`
  - [ ] Starter annual — $948/yr on the same product → copy `price_*` (optional; toggle hides without it)
  - [ ] Operator — recurring $399/mo → copy `price_*`
  - [ ] Operator annual — $3,828/yr → copy `price_*` (optional)
  - [ ] Top-up Small — one-time $29 (1,000 credits) → copy `price_*`
  - [ ] Top-up Medium — one-time $119 (5,000 credits) → copy `price_*`
  - [ ] Top-up Large — one-time $399 (20,000 credits) → copy `price_*`
        (packs MUST price above Operator's $0.016/credit — enforced by
        BillingInvariantsTest; see docs/operations/pricing-audit.md)
- [ ] dashboard.stripe.com/test/settings/billing/portal → **Activate test link**
      (without this, "Manage subscription" shows the friendly fallback error)
- [ ] Install Stripe CLI → `stripe login` → `stripe listen --forward-to localhost:8000/webhooks/stripe`
      → copy the `whsec_*` it prints; **keep this terminal open while testing**
- [ ] Paste into `.env`:

  ```bash
  STRIPE_KEY=pk_test_...
  STRIPE_SECRET=sk_test_...
  STRIPE_WEBHOOK_SECRET=whsec_...
  STRIPE_PRICE_STARTER=price_...
  STRIPE_PRICE_OPERATOR=price_...
  STRIPE_PRICE_STARTER_ANNUAL=price_...   # optional
  STRIPE_PRICE_OPERATOR_ANNUAL=price_...  # optional
  STRIPE_PRICE_TOPUP_SMALL=price_...
  STRIPE_PRICE_TOPUP_MEDIUM=price_...
  STRIPE_PRICE_TOPUP_LARGE=price_...
  ```

Test card: `4242 4242 4242 4242`, any future expiry, any CVC.
Failed-payment card (for the past-due banner): `4000 0000 0000 0341`.

---

## 3. Pusher (5 min) — degrades gracefully

**Skip symptom:** kanban / chat don't live-update (no errors, just no realtime).

- [ ] dashboard.pusher.com → Channels → Create app → copy app_id / key / secret / cluster
- [ ] Paste the four `PUSHER_*` values into `.env`
- [ ] OR, to test without realtime: `BROADCAST_CONNECTION=log`

---

## 4. Mail (AWS SES) — start now, finishes in ~24h

**Skip symptom:** verification + lead-assigned + billing emails land in
`storage/logs/laravel.log` instead of inboxes. **Local testing works anyway** —
grab the signed verification URL from the log.

- [ ] AWS SES → Verified identities → Create domain identity → add the 3 CNAME records to DNS
- [ ] SES → Account dashboard → Request production access (~24h to clear; sandbox sends only to verified addresses)
- [ ] IAM → user with `AmazonSESFullAccess` → create access key
- [ ] `.env`: `MAIL_MAILER=ses`, `MAIL_FROM_ADDRESS=hello@<verified-domain>`, AWS keys
- [ ] Verify: `php artisan mail:test you@example.com`

---

## 5. Apply config (1 min)

- [ ] `php artisan config:clear`
- [ ] `pnpm run build`
- [ ] Restart dev server + Stripe CLI listener

---

## 6. The 10-step lifecycle test

Run as a brand-new user in a fresh browser/incognito session:

1. [ ] **Register** → verification email (inbox, or `storage/logs/laravel.log`) → click link → land on dashboard
2. [ ] **Onboard** → answer the 4 profile questions → agent provisions on the native engine (no pool) → Done page shows install snippet
3. [ ] **Embed**: copy snippet from `/install` → paste into a local HTML file → floating button appears → chat works
4. [ ] **Subscribe**: `/billing` → Starter → Stripe Checkout with `4242…` → return → plan shows Starter, 2,500 credits
5. [ ] **Chat** in the dashboard → credits decrement per turn
6. [ ] **Top-up** → Checkout → webhook fires (watch the `stripe listen` terminal) → balance increases
7. [ ] **Cancel**: ⚙ Manage subscription → Stripe portal → cancel → return → `/billing` shows downgrade to free tier
8. [ ] **Leads**: trigger a capture via chat (or create manually) → appears on `/leads` → assign to a rep → rep gets bell + email
9. [ ] **Leads detail**: open the captured lead → notes autosave + conversation links work
10. [ ] **Analytics**: `/agents/{slug}/analytics` → counters, sparklines, funnel, heatmap populate
11. [ ] **Model tiers**: `/agents/versions` → switch the tier (e.g. Haiku → ChatGPT) → Publish → chat again; the reply style changes and the turn debits the new multiplier (verify in /billing history)

Bonus checks:
- [ ] RBAC: invite a second user as Editor → confirm they cannot top-up / delete the agent / open the billing portal
- [ ] Past-due banner: subscribe with `4000 0000 0000 0341` → next invoice fails → amber banner on `/billing`

---

## 7. Going to production (after the test pass)

- [ ] Stripe LIVE mode: new keys, re-create the 7 products, add a webhook
      endpoint at dashboard.stripe.com/webhooks (no CLI in prod)
- [ ] SES production access approved
- [ ] Deploy (Railway/Fly/etc.) with the same `.env` shape; run `php artisan migrate`
- [ ] Point the domain; confirm `APP_URL` matches (embed snippets bake it in)
- [ ] Merge the working branch to `main`

## Out of scope for this launch (by decision or phase)

- Free trial (product decision: none — $99 Starter is the entry point)
- Slack notifier, 2FA, audit log, CRM sync, transcript export (backlog)
- Voiceflow: fully deleted (git history keeps it recoverable)
