<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Alert recipient
    |--------------------------------------------------------------------------
    |
    | Where `hermes:alert` sends CRITICAL (audit-sentinel) / FAIL (system-check)
    | findings. Delivered via the SES mail stack on the dedicated "mail" queue.
    | Leave unset to disable email alerting — the command then logs a warning
    | instead of sending. Must be set BEFORE `config:cache` runs on deploy.
    |
    */

    'alert_email' => env('HERMES_ALERT_EMAIL'),

];
