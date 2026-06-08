# Public surface

The dashboard's externally-reachable, non-session-authenticated routes.

| Route | Auth | Purpose |
|---|---|---|
| `GET /api/public/stats` | none (anonymous) | Marketing-site metrics + scarcity counter |
| `POST /api/voiceflow/lead-captured/{agent:slug}` | per-agent `X-Webhook-Secret` header (constant-time compare) | Inbound Voiceflow Custom Action — captures qualified leads. Throttled `60/min/IP`. Tenancy is encoded in the slug; secret authenticates the caller. See [phase-5-voiceflow.md](./phase-5-voiceflow.md) for the contract. |
| `POST /api/voiceflow/webhooks/session/{agent:slug}` | per-agent `X-Webhook-Secret` header (constant-time compare) | Inbound Voiceflow session-lifecycle webhook (`runtime.session.*`, `runtime.call.*`). Throttled `120/min/IP`. Persists every event to `voiceflow_webhook_events` with idempotency on `(agent_id, event_id)` and reactively updates the matching `Conversation`. See [phase-15-voiceflow-wrapper.md](./phase-15-voiceflow-wrapper.md). |
| `POST /api/voiceflow/webhooks/org` | platform `services.voiceflow.org_webhook_secret` (Svix HMAC pending `svix/svix` dep) | Inbound Voiceflow org-events webhook (`organization.project.*`). Throttled `60/min/IP`. Reactively retires `voiceflow_project_pool` rows when a project is deleted upstream. See [phase-15-voiceflow-wrapper.md](./phase-15-voiceflow-wrapper.md). |
| `GET /` | none | Static framework Welcome page — serves no tenant data |

The rest of this document covers `/api/public/stats`, the only *anonymous*
endpoint. The [[landing-sse-pipeline|marketing site]] (`/home/theone/automation-landing`) reads it to show
live platform metrics + an operator-curated scarcity counter.

> Phase doc covering how this was built: [phase-14-public-stats.md](./phase-14-public-stats.md)
> Data flow: [architecture/public-stats-flow.md](./architecture/public-stats-flow.md)

## Contract

```
GET <DASHBOARD_URL>/api/public/stats
```

- **Auth:** none
- **Throttle:** `60` requests / minute / IP (`routes/api.php`)
- **Cache:** server-side 5 min (`Cache::remember('public_stats', 300, ...)`)
- **CORS:** open to `*` (response header, set inline by the controller)
- **Cache-Control:** `public, max-age=300` (CDN-friendly)

### Response shape

```json
{
  "founder_slots_remaining": 47,
  "founder_slots_total": 100,
  "next_cohort_label": "Rolling intake",
  "featured_proof": null,

  "teams_count": 2,
  "agents_active": 2,
  "leads_total": 0,
  "leads_qualified": 0,
  "messages_handled": 0,
  "messages_last_24h": 0,
  "time_saved_hours": 0,

  "last_activity_at": null,

  "display": {
    "teams_count": null,
    "agents_active": null,
    "leads_total": null,
    "leads_qualified": null,
    "messages_handled": null,
    "messages_last_24h": null,
    "time_saved_hours": null
  },

  "generated_at": "2026-06-04T10:21:00+00:00"
}
```

| Field | Source | Editable | Description |
|---|---|---|---|
| `founder_slots_remaining` | `platform_settings` | yes (CLI) | Scarcity counter — operator decrements by hand |
| `founder_slots_total` | `platform_settings` | yes | Cohort denominator |
| `next_cohort_label` | `platform_settings` | yes | Free-form (date / week / "Rolling") |
| `featured_proof` | `platform_settings` | yes | One-line testimonial — **render as text only** (XSS rule) |
| `teams_count` | `teams` table count | no | Total customer accounts |
| `agents_active` | `agents WHERE status='active'` count | no | Provisioned + running agents |
| `leads_total` | `leads` table count | no | Captured across all tenants |
| `leads_qualified` | `leads WHERE status='qualified'` count | no | Qualified by AI agents |
| `messages_handled` | `messages` table count | no | Total conversational turns |
| `messages_last_24h` | `messages WHERE created_at >= now-24h` count | no | Recency signal — "the platform is being used right now" |
| `time_saved_hours` | derived (`messages_handled * 3 / 60`) | no | Cumulative trust counter — 3-min/msg heuristic (Drift 2023 inbound-rep response time) |
| `last_activity_at` | most recent qualified-lead `created_at` | no | ISO 8601 timestamp (or `null` if none yet). NOT bucketed — landing renders as relative time |
| `display.*` | server-bucketed labels | no | Bucketed strings (see below) — landing site renders these. Every numeric `counts` key gets a `display.*` companion automatically |
| `generated_at` | server timestamp | no | When the cached snapshot was computed |

## Bucketing — why `display.*` exists

Raw counts are competitive intelligence when small. Broadcasting "2 operators"
is anti-social-proof. The server returns bucketed strings under `display.*`;
the landing site renders **those**, never the raw counts.

**Bucket ladder:** `10, 25, 50, 100, 250, 500, 1k, 2.5k, 5k, 10k, 25k, 50k, 100k, 250k, 500k, 1M`.

Snap **down** to the bucket and suffix `+`:

| Raw count | `display` |
|---|---|
| `0` – `9` | `null` (landing hides the field) |
| `10` – `24` | `"10+"` |
| `147` | `"100+"` |
| `1234` | `"1k+"` |
| `12000` | `"10k+"` |
| `1234567` | `"1M+"` |

Implementation: `PublicStatsController::bucket()` / `::formatBucket()`.

## Safety doctrine

**Safe to expose:**
- Operator-curated marketing copy
- Bucketed aggregate counts across all tenants
- Server-side timestamps

**Never expose:**
- Per-user data (names, emails, PII)
- Per-tenant data (team names, individual stats, revenue)
- Internal IDs, slugs, webhook secrets, API keys
- Anything that could identify a specific customer

**Render `featured_proof` as plain text.** The column is free-form (operator
typo could otherwise become stored XSS). React/Vue `{expr}` is safe;
`dangerouslySetInnerHTML` / `v-html` is not.

## Editable values — operator workflow

```bash
php artisan platform:set founder_slots_remaining 47
php artisan platform:set next_cohort_label "Starts March 15"
php artisan platform:set featured_proof "3.4× pipeline at Pendola"

# Inspect:
php artisan platform:set --list
```

Writes go through `PlatformSetting::put()`, which busts the cached stats
response. Landing site picks up the change on next request — no waiting
for the 5-min TTL.

Storage is one DB row per setting. Adding a new editable field doesn't
require a migration — read it in `PublicStatsController::compute()` with
a default, set it via the CLI, done.

## Adding a new computed field

One line in `PublicStatsController::compute()`:

```php
$counts['leads_won'] = Lead::where('status', 'won')->count();
```

Bucketing is applied automatically (the `display.*` loop covers every key
in `$counts`). Update the type in `automation-landing/src/lib/stats.ts`
to mirror, plus the `CountField` union if it's the kind of value that
should also stream over SSE.

## Files

- `app/Http/Controllers/PublicStatsController.php` — the controller + bucketing logic
- `app/Models/PlatformSetting.php` — key/value model with `value()`, `int()`, `put()` static helpers
- `app/Console/Commands/PlatformSet.php` — operator CLI
- `database/migrations/2026_06_03_230000_create_platform_settings_table.php` — schema
- `routes/api.php` — route definition (`throttle:60,1`)
- `tests/Feature/PublicStatsTest.php` — contract + bucket boundary tests
