<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe API credentials (test + live)
    |--------------------------------------------------------------------------
    |
    | STRIPE_KEY     — publishable key (pk_test_* in test mode, pk_live_* in prod)
    | STRIPE_SECRET  — secret key (sk_test_* / sk_live_*)
    | STRIPE_WEBHOOK_SECRET — endpoint signing secret. In local dev the Stripe
    |                        CLI prints this when you run `stripe listen`; in
    |                        prod it lives at dashboard.stripe.com/webhooks.
    |
    | All read at runtime so prod/staging/local can point at different
    | accounts via env without code changes.
    */

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // API version pin — Stripe ships breaking changes regularly; pin
        // explicitly so a Stripe-side rollout doesn't surprise us.
        'api_version' => env('STRIPE_API_VERSION', '2024-12-18.acacia'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Grant monthly credits on team creation (DEV/TEST convenience)
    |--------------------------------------------------------------------------
    |
    | In production, a team's credits are granted by the Stripe lifecycle
    | (invoice.paid webhook + the credits:grant-renewals safety-net), so a
    | fresh signup legitimately has 0 until it subscribes. That lifecycle
    | never fires without Stripe configured — so locally every test signup
    | dead-ends at "0 remaining". When this flag is true, a team's plan
    | allotment is granted on creation so test accounts are usable.
    |
    | MUST stay false in production (and is off by default + in the test
    | suite) — there it would hand out credits no one paid for.
    */
    'grant_on_signup' => env('BILLING_GRANT_ON_SIGNUP', false),

    /*
    |--------------------------------------------------------------------------
    | Stripe price IDs per plan + topup pack
    |--------------------------------------------------------------------------
    |
    | Set each to the price_* ID copied from Stripe Dashboard → Products
    | after creating the product in TEST mode (and again in LIVE mode for
    | production). Leaving any null means that plan won't show as
    | upgradable in the UI — useful during initial setup.
    */

    'stripe_price' => [
        // Monthly recurring prices.
        'starter' => env('STRIPE_PRICE_STARTER'),
        'operator' => env('STRIPE_PRICE_OPERATOR'),

        // Annual recurring prices — same Stripe Product, different Price ID
        // with interval=year. Leaving null disables the annual toggle for
        // that plan; the UI hides it gracefully. Set both to enable.
        'starter_annual' => env('STRIPE_PRICE_STARTER_ANNUAL'),
        'operator_annual' => env('STRIPE_PRICE_OPERATOR_ANNUAL'),

        'topup_small' => env('STRIPE_PRICE_TOPUP_SMALL'),
        'topup_medium' => env('STRIPE_PRICE_TOPUP_MEDIUM'),
        'topup_large' => env('STRIPE_PRICE_TOPUP_LARGE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom top-up (customer-chosen amount)
    |--------------------------------------------------------------------------
    |
    | Alongside the fixed packs, a customer can buy a custom credit amount.
    | This maps to a single Stripe Price created with `custom_unit_amount`
    | enabled — Stripe's hosted Checkout page renders an amount field and
    | enforces the min/max itself (set on the Price, mirrored here for the
    | UI copy). The chosen € amount comes back on the webhook as
    | `amount_total`, and we grant credits = amount_eur × credits_per_eur.
    |
    | credits_per_eur = 50 → €0.0200/credit, identical to the Large pack and
    | the BEST self-serve rate. It MUST stay above Operator's €0.01596/credit
    | floor or it goes margin-negative (BillingInvariantsTest guards this).
    |
    | Currency is EUR throughout — we sell in Europe.
    */
    'topup_custom' => [
        'price_id' => env('STRIPE_PRICE_TOPUP_CUSTOM'),
        'min_eur' => 10,
        'max_eur' => 2_000,
        'credits_per_eur' => 50,
    ],

];
