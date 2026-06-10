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

];
