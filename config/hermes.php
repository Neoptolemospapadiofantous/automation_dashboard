<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Hermes operator allowlist
    |--------------------------------------------------------------------------
    |
    | The developer/engineer tier that sits ABOVE the per-team Owner role. Only
    | these identities may view the local Hermes operator pages (/hermes-metrics
    | and /architecture). A team Owner who is NOT on this list is denied — this
    | is intentionally stricter than Role::Owner, scoped to the platform
    | engineer rather than any team's owner.
    |
    | Set HERMES_OPERATOR_EMAILS to a comma-separated list of emails. The
    | founder is always included so the admin tier works even before the env
    | var is configured (he is the sole admin until others are allowlisted).
    |
    */
    'operators' => array_values(array_unique(array_filter(array_merge(
        array_map('trim', explode(',', (string) env('HERMES_OPERATOR_EMAILS', ''))),
        ['neoptolemos.papadiofantous@gmail.com'],
    )))),
];
