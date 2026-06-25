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

// Financial integrity: the ledger must always sum to the live balances.
Schedule::command('credits:reconcile')->dailyAt('5:30');

// Token-spend tripwire: yesterday's platform-wide LLM cost vs SLA ceiling.
Schedule::command('runtime:spend-check')->dailyAt('5:45');

// Provider liveness tripwire: ping Anthropic/OpenAI/Gemini balances so an
// empty/throttled provider pages us (via the hermes-slack findings.json
// reader) instead of failing silently in front of a paying customer. The
// credit ledger and provider API billing are separate ledgers.
Schedule::command('providers:health-check')->hourly();

// Hermes audit agents — daily sweeps (no-LLM bash scripts). Their reports
// land in data/agents/*/; `composer hermes-status` summarizes. The full
// watchdog (hermes-fast) stays on-demand + CI; these cover drift that
// only shows over time (CVEs, outdated deps, disk, log errors, secrets).
// Delivery of CRITICAL/FAIL findings to Slack now lives in the separate
// hermes-slack project, which consumes these data/agents/*/findings.json.
Schedule::exec('bash scripts/agents/audit_sentinel.sh')->dailyAt('6:00');
Schedule::exec('bash scripts/agents/update_inspector.sh')->weeklyOn(1, '6:10');
Schedule::exec('bash scripts/agents/system_check.sh')->everySixHours();
