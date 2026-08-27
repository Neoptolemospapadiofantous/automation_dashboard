# Buying-journey verification runbook

How to prove the end-to-end buying lifecycle — register → subscribe →
webhook → credits → usage → top-up → cancel — actually works, in Stripe
**test mode**, locally, without touching live money.

Last live-config audit: 2026-07-09 (all green — see the checklist at the
bottom for what "green" means and how to re-audit).

## One-time setup

1. **Test keys.** Stripe Dashboard → toggle *Test mode* → Developers →
   API keys. You need `pk_test_…` and `sk_test_…` from the SAME account
   as live (acct `…CNnpWOF42r`).
2. **Test-mode prices.** Live `price_…` IDs do not exist in test mode.
   Mirror them once (idempotent — re-running finds them by nickname):

   ```bash
   # with sk_test in $KEY — creates product+price pairs matching live
   for spec in "Starter Monthly:9900:month" "Operator Monthly:39900:month" \
               "Starter Annual:99000:year" "Operator Annual:399000:year"; do
     IFS=: read -r nick amount interval <<<"$spec"
     curl -s https://api.stripe.com/v1/prices \
       -H "Authorization: Bearer $KEY" \
       -d "unit_amount=$amount" -d currency=eur \
       -d "recurring[interval]=$interval" -d "nickname=$nick" \
       -d "product_data[name]=Flowstack $nick"
   done
   # top-ups: one-time 2900 / 11900 / 39900 + custom (min 1000, max 200000)
   ```

3. **`.env` swap block.** Keep the live block commented; paste the test
   values for: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`
   (from step 4), and all 8 `STRIPE_PRICE_*` (test-mode IDs from step 2).
   `php artisan config:clear` after every swap. **Swap back when done —
   the working copy normally carries LIVE keys.**

4. **Webhook forwarding.** The CLI is installed (`stripe` 1.42.13):

   ```bash
   stripe login   # once, browser pairing
   stripe listen --forward-to http://127.0.0.1:8000/webhooks/stripe
   # prints whsec_… → that is STRIPE_WEBHOOK_SECRET for the test session
   ```

## The journey (assert each step)

Serve the app (`php artisan serve --port=8000`) with the test env, then
in a browser:

| # | Action | Expected |
|---|--------|----------|
| 1 | Register a fresh user | lands in onboarding; personal team + agent provisioned |
| 2 | Finish onboarding | dashboard shows setup checklist |
| 3 | Billing → subscribe Starter (monthly) | redirected to checkout.stripe.com, **test-mode banner visible** |
| 4 | Pay with `4242 4242 4242 4242`, any future expiry/CVC | redirected to /subscribe/success |
| 5 | Watch `stripe listen` output | `checkout.session.completed` → 200 from local app |
| 6 | Billing page | plan = Starter, 2,500 monthly credits granted, usage 0% |
| 7 | Chat → send a message | reply arrives; credit balance decreases; Billing history shows the debit |
| 8 | Billing → top-up Small (€5) | checkout → success → +credits, history row, rollover noted |
| 9 | Billing → manage/portal | Stripe portal opens; cancel is offered |
| 10 | Cancel in portal | `customer.subscription.deleted` forwarded; app reflects cancellation per grace-period rules |
| 11 | Annual toggle (optional) | Starter Annual €90 checkout works the same |

Failure at 5 = webhook secret/forwarding; at 6 = webhook handler or
price→plan mapping; at 7 = credit meter/runtime; at 9–10 = portal config.

## Cleanup

- Kill `stripe listen`, restore the live `.env` block, `php artisan
  config:clear`.
- Test-mode data (customers/subs) is disposable; delete via Dashboard →
  test mode if desired.

## Live-config audit (re-run anytime, read-only, no charge)

All checks passed 2026-07-09:

- `STRIPE_PRICE_*` exist + active, amounts match §3.4 (€9/€19/€39
  monthly; €90/€190/€390 annual; top-ups €5/€15/€40/custom €10–2,000).
  **Live Prices created 2026-08-27** on acct_1LkQudCNnpWOF42r and verified
  amount-by-amount against `Plan`/`TopUpPack`; repo `.env` points at them.
  The pre-repricing Prices (€99/€399/€29/€119/€399) were deliberately LEFT
  ACTIVE — existing subscriptions bill against them, and archiving buys
  nothing since checkout only ever uses the env-configured ids.
  ⚠️ Forge prod env still points at the OLD ids ON PURPOSE: prod runs the
  pre-repricing code, and swapping ids before the deploy would display the
  old price while charging the new one. Run
  `scratchpad/forge-stripe-repricing.sh` immediately AFTER deploying.
- Webhook endpoint `https://app.flowstack.run/webhooks/stripe` enabled,
  events: checkout.session.completed, invoice.paid,
  invoice.payment_failed, customer.subscription.updated/.deleted.
- Route exists + CSRF-exempt; unsigned POST → 400 `signature_invalid`.
- No stuck deliveries (`pending_webhooks=0` across recent events); last
  full live purchase chain completed 2026-06-25 (checkout → subscription
  → invoice paid); 1 active subscription (Operator Monthly).
- Billing portal: 1 active default config, subscription cancel enabled.
- Note: webhook endpoint pinned to api_version 2022-08-01 (works; if
  Cashier is ever upgraded expecting newer payloads, bump this in the
  Stripe dashboard deliberately).

Next hardening steps (not yet done): promote the journey into CI's e2e
job with a test-mode secret, and add a webhook-delivery-failure check to
grid-sentinel.
