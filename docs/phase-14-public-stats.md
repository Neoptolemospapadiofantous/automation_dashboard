# Phase 14 — Public stats surface + marketing-site integration

Expose platform-wide metrics to the marketing site
(`/home/theone/automation-landing`) so it can show live trust signals
("X operators on the platform") and an operator-curated scarcity counter
("47 of 100 founder slots remaining"). Sub-second updates on the landing
side via SSE; near-zero ongoing cost.

> Long-lived contract reference: [public-surface.md](./public-surface.md)
> Dashboard data flow: [architecture/public-stats-flow.md](./architecture/public-stats-flow.md)
> Landing SSE pipeline: [architecture/landing-sse-pipeline.md](./architecture/landing-sse-pipeline.md)

## What this phase delivers

### Dashboard side

- **`platform_settings` table** — `key` PK / `value` text / `updated_at`.
  Editable platform-wide key/value rows for marketing scarcity counters
  (`founder_slots_remaining`, `next_cohort_label`, `featured_proof`, …)
  that operators tweak by hand without a deploy.
- **`PlatformSetting` model** with `value()` / `int()` / `put()` static
  helpers. `put()` automatically busts the cached stats response so
  operator edits land immediately.
- **`PublicStatsController`** + `GET /api/public/stats` route — the only
  unauthenticated, CORS-open endpoint. Combines editable settings with
  live aggregate counts from existing tables (`teams`, `agents`, `leads`,
  `messages`). Cached server-side 5 min; throttled `60`/min/IP.
- **Bucketing** — every aggregate count is also returned as a bucketed
  string under `display.*` (`"10+"`, `"100+"`, `"1k+"`, snapped DOWN; `null`
  below 10). Landing site reads `display.*` so embarrassingly small
  early-stage numbers never become public anti-social-proof.
- **`php artisan platform:set <key> <value>`** — operator CLI for editable
  values. `--list` shows everything.
- **Tests** — 6 cases covering shape, CORS headers, cache hit/bust,
  16 bucket boundary cases.

### Landing-site side (`/home/theone/automation-landing`)

- **`src/lib/stats.ts`** — typed `PlatformStats` + `CountField` union +
  `getPlatformStats()` (ISR-cached for SEO-correct first paint) +
  `fetchPlatformStatsFresh()` (no-cache, used by the broadcaster).
- **`src/lib/stats-broadcaster.ts`** — module-level singleton. Polls the
  dashboard every 5s, diffs `display.*`, broadcasts to all SSE
  subscribers only when something changed. Stops the poll interval when
  subscribers drop to 0.
- **`src/app/api/stats/stream/route.ts`** — Next.js SSE route handler
  (Node runtime). Subscribes the request, sends immediate initial event,
  heartbeats every 30s, cleans up on disconnect via the request abort
  signal.
- **`src/components/live-stat.tsx`** — `"use client"` island. Takes a
  server-rendered `initial` value, opens an `EventSource` to
  `/api/stats/stream`, re-renders on incoming events. Supports a
  `fallback` ReactNode for when the bucket is `null` (default: render
  nothing; pass `<span>—</span>` for "instrument calibrating" look).
- **`src/components/sections/live-outcomes.tsx`** — server component
  that renders the live-counts strip with one `<LiveStat>` per cell.
- **`src/components/sections/founder-slots.tsx`** — scarcity callout.
  Server-only render (no SSE) because the founder-slots value only
  changes when the operator runs `platform:set`; ISR's 5-min refresh
  window is correct for that.

## Routes also renamed in this phase

Untangled the long-standing `agent.*` (chat panel) vs `agents.*`
(agents CRUD) one-letter collision that bit me multiple times during
the earlier UI polish pass:

| Before | After |
|---|---|
| `GET /agent` → `agent.index` | `GET /chat` → `chat.index` |
| `POST /agent/launch` → `agent.launch` | `POST /chat/launch` → `chat.launch` |
| `POST /agent/interact` → `agent.interact` | `POST /chat/interact` → `chat.interact` |
| `GET /agent/health` → `agent.health` | `GET /chat/health` → `chat.health` |
| `resources/js/Pages/Agent/Index.vue` | `resources/js/Pages/Chat/Index.vue` |

Phase 5 doc updated to reflect the new names.

## Why bucketing matters

Bucketed strings (`display.*`) are competitive-intelligence hygiene.
Showing `"2 operators"` on the landing page during early growth is
*anti*-social-proof: visitors read it as "this thing has no traction."
Bucketing collapses small real numbers to `null` (landing hides the
cell) and rounds larger numbers DOWN to a marketing-friendly tier with
a `+` suffix:

| Raw | `display` |
|---|---|
| `2` | `null` (hidden) |
| `12` | `"10+"` |
| `147` | `"100+"` |
| `1234` | `"1k+"` |

Bucket ladder: `10, 25, 50, 100, 250, 500, 1k, 2.5k, 5k, 10k, 25k, 50k,
100k, 250k, 500k, 1M`. Snap down, never round up.

Once the underlying count crosses the lowest bucket the cell appears
automatically — no landing-side change needed.

## Why SSE on the landing side, not the dashboard

PHP-FPM holds one worker per request. SSE on Laravel = one worker held
hostage per visitor. Untenable at landing-page traffic.

The Next.js Node process, by contrast, handles thousands of concurrent
open connections in one event loop. **Move the persistent-connection
cost to Node**, keep Laravel a normal request-response app.

The full architecture rationale is in
[architecture/landing-sse-pipeline.md](./architecture/landing-sse-pipeline.md);
the short version:

- Dashboard remains stateless PHP — no Octane, no Reverb, no Pusher
  bills, no new infra.
- Landing Node process runs ONE singleton poller that fetches the
  dashboard every 5s. ALL visitor SSE connections subscribe to that
  singleton. Dashboard sees ~1 request per 5s regardless of visitor
  count.
- Diff-and-broadcast: idle visitors only get 30s heartbeats; payload
  events fly only when `display.*` actually changes.
- Stops polling when subscribers == 0 → zero cost when the landing
  page has no readers.

## Alternative: polling

If the landing site ever moves to a serverless host (Vercel functions /
Edge / Lambda) the singleton design breaks (no shared memory across
invocations). Fall back to:

- **Tighten the proxy:** edge-cache `/api/stats` for 5s, client polls
  every 5s.
- **Result:** visitors see updates within ~5s (good enough for marketing
  copy), 99.5% of polls served from edge cache, dashboard hit at most
  once per 5-min ISR window.
- Less code, no broadcaster, no `EventSource`. Worse latency but works
  on any host.

## Editable platform_settings — current keys

| Key | Default | Use |
|---|---|---|
| `founder_slots_remaining` | `100` | Scarcity counter |
| `founder_slots_total` | `100` | Cohort denominator |
| `next_cohort_label` | `"Rolling intake"` | "Starts March 15" / "Rolling" |
| `featured_proof` | `null` | One-line testimonial — **render as text only** |

Adding more is zero-migration: pick a key, set it via CLI, read it in
`PublicStatsController::compute()` with a default.

Candidate keys we discussed but haven't shipped:
`cohort_starts_at`, `nps_score`, `customer_logos`, `recent_wins`,
`case_study_url`, `pricing_floor`, `team_size`, `last_deploy_at`,
`headline_claim`, `homepage_promo`.

## Files

### Dashboard

- `database/migrations/2026_06_03_230000_create_platform_settings_table.php`
- `app/Models/PlatformSetting.php`
- `app/Http/Controllers/PublicStatsController.php`
- `app/Console/Commands/PlatformSet.php`
- `routes/api.php` — adds the throttled public route
- `tests/Feature/PublicStatsTest.php`

Chat-route rename:
- `routes/web.php` — `/chat`, `chat.*` route names
- `resources/js/Pages/Chat/Index.vue` (moved from `Pages/Agent/`)
- `resources/js/Layouts/AppLayout.vue` — sidebar link
- `resources/js/Pages/Onboarding/Done.vue` — CTA link
- 6 test files — route-name updates

### Landing (`/home/theone/automation-landing`)

- `src/lib/stats.ts` — types + ISR + fresh fetchers + `formatStat()`
- `src/lib/stats-broadcaster.ts`
- `src/app/api/stats/stream/route.ts`
- `src/components/live-stat.tsx`
- `src/components/sections/live-outcomes.tsx`
- `src/components/sections/founder-slots.tsx`
- `.env.example` — adds `DASHBOARD_API_URL` + `NEXT_PUBLIC_DASHBOARD_URL`

## Operator runbook

```bash
# adjust scarcity counter as signups come in
php artisan platform:set founder_slots_remaining 47

# refresh marketing copy
php artisan platform:set next_cohort_label "Starts March 15"
php artisan platform:set featured_proof "3.4× pipeline at Pendola"

# see everything currently set
php artisan platform:set --list

# pull the raw JSON to verify
curl http://localhost:8000/api/public/stats | jq
```

Edits via `platform:set` bust the cache → landing site picks them up
within ~5s (next broadcaster poll cycle).

## Next ideas

- Add `last_activity_ago` (time since most recent qualified lead) for a
  strong recency signal on the landing page.
- Add `time_saved_hours` derived metric (`messages × 3min / 60`) as a
  cumulative trust counter.
- Add `messages_last_24h` to prove the platform is *being used*, not
  just signed up for.
- If landing moves to a serverless host, swap SSE for the polling
  fallback documented above.
