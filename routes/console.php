<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Native-runtime housekeeping: drop sessions idle past the retention
// window (default 30 days, matching the embed visitor cookie TTL).
Schedule::command('runtime:prune-sessions')->daily();

// Credit-renewal safety net: monthly grants for annual subscriptions +
// self-heal for missed invoice.paid webhooks (see the command docblock).
Schedule::command('credits:grant-renewals')->daily();
