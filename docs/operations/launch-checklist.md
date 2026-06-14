# Launch checklist — flowstack.run → production

Status as of 2026-06-14: **code complete, merged to `main`, CI green, audit PASS.**
441 tests passing · deployed to **app.flowstack.run** via Laravel Forge ·
prod DB = `automation`. Remaining work is **environment/service wiring**, below.

Domains: landing = `flowstack.run` · dashboard app = **`app.flowstack.run`** ·
realtime websocket = `ws.flowstack.run`.

Work top-to-bottom. Each section says what breaks if you skip it. Secrets go
into **Forge → Site → Environment** (web panel); nothing here needs a terminal
except the verification commands.

---

## 0. Pre-flight

- [ ] `main` is the live branch (the `runtime-native-l1` work is merged).
- [ ] Unlock any pre-verification users (email verification is enforced):
  ```bash
  php artisan tinker --execute="\App\Models\User::whereNull('email_verified_at')->update(['email_verified_at' => now()]);"
  ```

---

## 1. App basics — BLOCKING
**Skip symptom:** debug info leaks; wrong links in emails/embeds.

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_URL=https://app.flowstack.run`
- [ ] `APP_NAME=Flowstack`
- [ ] `APP_KEY` is set (generated)

---

## 2. Native runtime keys — BLOCKING
**Skip symptom:** agent health card red; chat/embed return 503.

- [ ] `ANTHROPIC_API_KEY` — https://console.anthropic.com → API keys (the chat loop)
- [ ] `OPENAI_API_KEY` — https://platform.openai.com/api-keys (KB embeddings)
- [ ] `GEMINI_API_KEY` — OPTIONAL, https://aistudio.google.com/apikey (unlocks the
      Gemini tier; must be a **paid-tier** key — free keys train on customer data)
- [ ] Set a monthly spend cap in each provider console (platform backstop)
- [ ] Verify: agent page → health button → `engine: native, ok: true`

---

## 3. Stripe LIVE — BLOCKING for billing
**Skip symptom:** Subscribe / Top-up / Manage-subscription → error.
Pricing is **EUR**. Test mode is already wired; this is the LIVE setup.

- [ ] Activate live mode (business details) → https://dashboard.stripe.com/apikeys
      → copy `pk_live_*` + `sk_live_*`
- [ ] https://dashboard.stripe.com/products (live) → create, copy each `price_*`:
  - [ ] Starter — recurring **€99/mo**
  - [ ] Starter annual — **€990/yr** on the same product (optional; toggle hides without it)
  - [ ] Operator — recurring **€399/mo**
  - [ ] Operator annual — **€3,990/yr** (optional)
  - [ ] Top-up Small — one-time **€29** (1,000 credits)
  - [ ] Top-up Medium — one-time **€119** (5,000 credits)
  - [ ] Top-up Large — one-time **€399** (20,000 credits)
  - [ ] Custom top-up — one price with **`custom_unit_amount`** enabled,
        min €10 / max €2,000 (credits = amount × 50, i.e. €0.02/credit)
  - Packs/custom MUST price above Operator's **€0.01596/credit** — enforced by
    `BillingInvariantsTest`; see `docs/operations/pricing-audit.md`.
- [ ] https://dashboard.stripe.com/settings/billing/portal → activate the portal
      (else "Manage subscription" shows the fallback error)
- [ ] Webhook: https://dashboard.stripe.com/webhooks → Add endpoint →
      **`https://app.flowstack.run/webhooks/stripe`** → select events:
      `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`,
      `customer.subscription.updated`, `customer.subscription.deleted` → copy `whsec_*`
- [ ] Forge `.env`:
  ```bash
  STRIPE_KEY=pk_live_...
  STRIPE_SECRET=sk_live_...
  STRIPE_WEBHOOK_SECRET=whsec_...
  STRIPE_PRICE_STARTER=price_...
  STRIPE_PRICE_OPERATOR=price_...
  STRIPE_PRICE_STARTER_ANNUAL=price_...    # optional
  STRIPE_PRICE_OPERATOR_ANNUAL=price_...   # optional
  STRIPE_PRICE_TOPUP_SMALL=price_...
  STRIPE_PRICE_TOPUP_MEDIUM=price_...
  STRIPE_PRICE_TOPUP_LARGE=price_...
  STRIPE_PRICE_TOPUP_CUSTOM=price_...
  ```

---

## 4. Mail — Zoho (transactional), domain `flowstack.run`
**Skip symptom:** verification + password-reset + lead/handoff/credit emails
never arrive (they fall back to the log).

AWS SES is **not** needed — the app only sends transactional mail. (SES would
only be for bulk/marketing, which this app doesn't do.)

- [ ] Zoho Mail Admin (EU) → https://mailadmin.zoho.eu → add + verify `flowstack.run`
- [ ] Add DNS records at the registrar (values from the Zoho panel):
  - MX: `mx.zoho.eu` (10), `mx2.zoho.eu` (20), `mx3.zoho.eu` (50)
  - SPF (TXT `@`): `v=spf1 include:zohomail.eu ~all`
  - DKIM (TXT `zmail._domainkey`): key from the panel
  - Verification TXT + optional DMARC (`_dmarc`: `v=DMARC1; p=none; rua=mailto:hello@flowstack.run`)
- [ ] Create mailbox `hello@flowstack.run`
- [ ] App password: https://accounts.zoho.eu/home#security/app-passwords (needs 2FA)
- [ ] Forge `.env`:
  ```bash
  MAIL_MAILER=smtp
  MAIL_HOST=smtp.zoho.eu
  MAIL_PORT=465
  MAIL_SCHEME=smtps
  MAIL_USERNAME=hello@flowstack.run
  MAIL_PASSWORD=<app password>
  MAIL_FROM_ADDRESS=hello@flowstack.run
  MAIL_FROM_NAME="Flowstack"
  ```
- [ ] Verify: `php artisan mail:test you@example.com`

---

## 5. Realtime — Laravel Reverb (self-hosted)
**Skip symptom:** kanban / dashboard don't live-update; "Offline" pill (no errors).
We use Reverb (first-party, self-hosted) — no Pusher account.

- [ ] Forge `.env` — set BEFORE the deploy build (the `VITE_REVERB_*` bake into JS):
  ```bash
  BROADCAST_CONNECTION=reverb
  REVERB_APP_ID=...            # any unique id
  REVERB_APP_KEY=...           # generate (or reuse reverb:install output)
  REVERB_APP_SECRET=...
  REVERB_HOST=ws.flowstack.run
  REVERB_PORT=443
  REVERB_SCHEME=https
  REVERB_SERVER_HOST=0.0.0.0
  REVERB_SERVER_PORT=8080
  ```
- [ ] DNS: `ws.flowstack.run` → the app server
- [ ] nginx: a `ws.flowstack.run` site with TLS proxying to `127.0.0.1:8080`,
      passing the websocket upgrade headers (`Upgrade` / `Connection`)
- [ ] Forge daemon: `php artisan reverb:start` (auto-restart)
- [ ] Needs the queue worker (§6) — broadcasts are queued.

---

## 6. Forge daemons & scheduler — BLOCKING for background work
**Skip symptom:** no credit renewals/reconcile/spend-check/audit; no emails on
queued events; no live broadcasts; search index never updates.

- [ ] **Scheduler**: enable Forge's scheduler (`* * * * * php artisan schedule:run`).
      Gates: `credits:grant-renewals`, `credits:reconcile`, `runtime:spend-check`,
      `runtime:prune-sessions`, the daily **audit sentinel**, weekly update inspector.
- [ ] **Queue worker** daemon: `php artisan queue:work` (with `QUEUE_CONNECTION=database`,
      `SCOUT_QUEUE=true`). Processes broadcasts, queued mail, scout indexing.
- [ ] **Reverb daemon** (§5).
- [ ] Confirm `BILLING_GRANT_ON_SIGNUP=false` in prod.

---

## 7. Apply config + deploy
- [ ] Deploy on Forge (Quick Deploy or "Deploy Now") — runs `composer install`,
      `migrate --force`, `pnpm build`.
- [ ] Forge's deploy script handles `config:cache` / `config:clear`.

---

## 8. Lifecycle smoke test (LIVE)
Run as a brand-new user in a fresh/incognito session against `app.flowstack.run`:

1. [ ] **Register** → verification email arrives (real inbox now) → click → dashboard
2. [ ] **Onboard** → 4 profile questions → agent provisions on the native engine → install snippet shown
3. [ ] **Embed**: copy snippet from `/install` → paste into a test page → floating widget chats
4. [ ] **Subscribe**: `/billing` → Starter → Stripe Checkout → plan = Starter, 2,500 credits
5. [ ] **Chat** in the dashboard → credits decrement per turn
6. [ ] **Top-up** (a pack **and** the custom €-amount) → webhook fires → balance increases
7. [ ] **Annual toggle** → Subscribe annual → €990/yr price used
8. [ ] **Cancel**: ⚙ Manage subscription → Stripe portal → cancel → downgrade to Free
9. [ ] **Leads**: capture via chat → appears on `/leads` (live, via Reverb) → assign → rep gets bell + email
10. [ ] **Model tiers**: `/agents/versions` → switch tier → Publish → reply style + credit multiplier change
- [ ] RBAC: invite an Editor → confirm they cannot top-up / delete the agent / open the billing portal
- [ ] Past-due banner: subscribe with `4000 0000 0000 0341` → invoice fails → amber banner on `/billing`

Test cards (only meaningful before live): `4242 4242 4242 4242` (ok),
`4000 0000 0000 0341` (fails on renewal).

---

## Out of scope for this launch (by decision or phase)
- Free trial (product decision: none — €99 Starter is the entry point)
- AWS SES / S3 (not used — Zoho covers mail; KB uploads parse to text, no file storage)
- Slack notifier, general action audit log, DSR intake, consent records, transcript export (backlog)
- Typesense search (optional — DB `LIKE` fallback ships by default)
