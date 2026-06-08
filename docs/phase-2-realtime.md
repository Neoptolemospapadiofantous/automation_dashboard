# Phase 2 — Real-time backbone

Prove the live-update pipeline end to end with a "live tick" feature every
connected browser receives instantly — no polling, no reload.

## What this phase delivers

- **Broadcasting via Pusher** (`laravel-echo` + `pusher-js`), protocol-
  compatible with self-hosted **Reverb** so switching later is a one-env-var
  change, not a rewrite.
- A **`DashboardTick`** broadcast event on a public `dashboard` channel.
- A **controller + route** (`POST /dashboard/tick`) to fire a tick; the
  broadcast is what updates every client, the HTTP response is just an ack.
- **Echo client bootstrap** (`resources/js/echo.js`) that initialises only when
  credentials are present, so the app boots cleanly without them.
- A reusable **`useEcho` composable** and a **`LiveTick` Vue widget** on the
  dashboard with a LIVE/OFFLINE indicator.
- **Feature tests** covering auth + broadcast dispatch.

## Key files

| File | Purpose |
| ---- | ------- |
| `app/Events/DashboardTick.php` | `ShouldBroadcast` event on the `dashboard` channel. |
| `app/Http/Controllers/DashboardTickController.php` | Fires a tick. |
| `resources/js/echo.js` | Laravel Echo bootstrap (Pusher protocol). |
| `resources/js/composables/useEcho.js` | Subscribe to a channel for a component's lifetime. |
| `resources/js/Components/LiveTick.vue` | Live widget on the dashboard. |
| `tests/Feature/DashboardTickTest.php` | Auth + broadcast tests. |

## Verify

```bash
php artisan test --filter=DashboardTickTest
pnpm run build
```

To see cross-browser live ticks: set `PUSHER_*` + `BROADCAST_CONNECTION=pusher`
in `.env`, run `php artisan queue:work`, open `/dashboard` in two tabs and click
**Fire a live tick**. Without credentials the widget shows **OFFLINE** but the
app runs fine.

## Next

[[phase-3-leads|Phase 3 broadcasts real domain events (leads) over this same pipeline.]]
