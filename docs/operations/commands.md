---
type: ops
tags: [operations, artisan, scheduler, billing]
---

# Artisan commands & scheduled jobs

Consolidated reference for every custom `php artisan` command in
[`app/Console/Commands/`](../../app/Console/Commands) and the schedule
that drives them ([`routes/console.php`](../../routes/console.php) — this
app has **no** `app/Console/Kernel.php`; scheduling lives in the route
file, Laravel 11 style). Code is the source of truth; this is a map.

Commands split two ways: **scheduled** (run automatically by the cron)
and **operator CLIs** (run by hand). The financial-integrity pair —
`credits:reconcile` and `runtime:spend-check` — exit **non-zero** so the
scheduler's output surfaces failures to ops.

## All commands

| Command | Signature / options | Does | Run |
|---|---|---|---|
| `credits:grant-renewals` | `--dry-run` | Grants the monthly allotment to every **active paid** team whose `credits_renewed_at` is null or older than 32 days. Covers annual cycles (invoice once/year) + self-heals missed `invoice.paid` webhooks. Monthly teams renew via webhook inside the window, so no double-grant. Business plans excluded (per-contract). | Scheduled — daily |
| `credits:reconcile` | _(none)_ | Asserts `SUM(credit_transactions) == credit_balance + topup_balance` for every team. Lists drift and exits non-zero. | Scheduled — daily 05:30 |
| `runtime:spend-check` | `--date=YYYY-MM-DD` (default: yesterday) | Prices a day's `runtime_usage` rollups at per-tier provider rates; fails if platform-wide LLM cost crossed the SLA ceiling. | Scheduled — daily 05:45 |
| `runtime:costs` | `--month=YYYY-MM` (default: current) | Per-team token spend vs. plan revenue + margin (platform-margin report). | Manual |
| `runtime:prune-sessions` | `--days=N` (default: 30) | Deletes native-runtime sessions idle past the retention window (matches the embed visitor cookie TTL). | Scheduled — daily |
| `conversations:prune` | `--days=N` (default 365), `--force` | **Opt-in** archival. Dry-run unless `--force`. App keeps all conversations forever by default; run only to reclaim storage. Messages cascade. | Manual |
| `platform:set` | `{key?} {value?}` `--list` | Sets/lists editable `platform_settings` (public-stats scarcity: `founder_slots_remaining`, `next_cohort_label`, `featured_proof`, …). Writing busts the `/api/public/stats` cache. | Manual (operator) |
| `mail:test` | `{to}` (recipient) | Sends a probe email through the configured mailer; prints resolved driver + from-address. Warns when `MAIL_MAILER=log`. | Manual |

> `runtime:prune-sessions --days` overrides the window (floored at 1).
> `platform:set` with no key, or `--list`, prints the current table (or
> defaults, sourced from `PublicStatsController`). See
> [[public-surface]] for what the public-stats values feed.

## The schedule

Defined in [`routes/console.php`](../../routes/console.php) via the
`Schedule` facade. All times are server-local.

| Entry | Cadence | Purpose |
|---|---|---|
| `runtime:prune-sessions` | `daily()` | Bound the unbounded sessions table (audit finding). |
| `credits:grant-renewals` | `daily()` | Renewal safety net (annual cycles + missed webhooks). |
| `credits:reconcile` | `dailyAt('5:30')` | Ledger-vs-balance integrity; non-zero on drift. |
| `runtime:spend-check` | `dailyAt('5:45')` | Daily token-spend tripwire vs. SLA ceiling. |
| `bash scripts/agents/audit_sentinel.sh` | `dailyAt('6:00')` | Hermes audit sweep (CVEs, secrets, .env drift). |
| `bash scripts/agents/update_inspector.sh` | `weeklyOn(1, '6:10')` | Hermes outdated-deps sweep (Mondays). |
| `bash scripts/agents/system_check.sh` | `everySixHours()` | Hermes runtime-health sweep (disk, logs, queue). |

The three `Schedule::exec(...)` entries are no-LLM bash audit agents;
their reports land in `data/agents/*/` and `composer hermes-status`
summarizes. Delivery of CRITICAL/FAIL findings now lives in the separate
`hermes-slack` project, which consumes those reports. See [[hermes/README|Hermes]].

### Running the scheduler

- **Dev:** `php artisan schedule:work` runs every minute in-process. It's
  the `cron` pane of the `composer dev` concurrently stack — start the
  full dev environment and it's already running.
- **Prod:** the scheduler needs a real system cron entry calling
  `schedule:run` once a minute:
  ```cron
  * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
  ```
  Without it, none of the scheduled jobs above fire.

## Grouped by purpose

### Billing & credits

`credits:reconcile`, `credits:grant-renewals`, `runtime:spend-check`,
`runtime:costs`. The first three are scheduled; `runtime:costs` is the
manual report you reach for when a check fires.

- **`credits:reconcile`** — non-zero exit means a code path moved credits
  without writing an audit row (or vice versa). This is the bug class a
  credits product cannot tolerate silently: investigate the named team(s)
  before trusting any balance. Sum-consistent only since the
  `expire_monthly` rows (2026-06-12); pre-existing history is baselined
  once after deploy.
- **`runtime:spend-check`** — non-zero exit ("SPEND CEILING BREACHED")
  means yesterday's platform-wide LLM cost crossed
  `sla.spend.daily_ceiling_usd`
  ([`config/sla.php`](../../config/sla.php), default `$25/day`, env
  `SLA_DAILY_SPEND_CEILING_USD`). Signals a runaway agent, abusive team,
  or tool loop. The check tells you _that_ it happened; run `runtime:costs`
  to find _who_.
- **`runtime:costs`** — read-only margin view. A negative/low margin
  column for a team means their token spend is eating the plan revenue.
  Top-ups show in the credits column, not revenue (kept conservative);
  only active-subscription revenue counts (phantom-revenue guard).

### Housekeeping

`runtime:prune-sessions` (scheduled daily) bounds the runtime-session
table. `conversations:prune` is opt-in and **off by default** — the app
retains all conversations indefinitely; only run it to deliberately
reclaim storage, and only with `--force` after checking the dry run.

### Ops

`platform:set` curates the public-stats scarcity values the marketing
site reads (see [[public-surface]]); `mail:test` verifies mail delivery
config. Both manual.

## Related

- [[public-surface]] — what `platform:set` and public stats feed.
- [[hermes/README|Hermes]] — the scheduled bash audit agents.
- Pricing/credit model context: `docs/operations/pricing-audit.md`,
  `docs/operations/economics.md`.
