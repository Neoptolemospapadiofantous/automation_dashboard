<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Stripe price IDs per plan
    |--------------------------------------------------------------------------
    |
    | Set these to your Stripe Product → Price IDs once Cashier is wired up
    | (Phase H3). Leave null and the plan will simply not appear as upgradable
    | in the UI — useful during local dev.
    */
    'stripe_price' => [
        'pro' => env('BILLING_STRIPE_PRICE_PRO'),
        'business' => env('BILLING_STRIPE_PRICE_BUSINESS'),
    ],
];
