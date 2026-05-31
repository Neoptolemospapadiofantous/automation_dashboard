# Automation Dashboard

A real-time lead-delegation dashboard built with **Laravel 12 + Inertia 2 + Vue 3
(Jetstream, teams)**. Every change — incoming leads, agent conversations,
qualification status, and delegation — ticks **live across all connected
screens** over WebSockets. Lead conversations are handled by **Voiceflow**
agents, proxied server-side through Laravel so the API key never reaches the
browser.

## Stack

| Layer        | Choice                                                        |
| ------------ | ------------------------------------------------------------ |
| Backend      | Laravel 12, PHP 8.2+                                          |
| Auth / UI    | Jetstream (Inertia + Vue 3), teams enabled, Sanctum          |
| Frontend     | Vue 3, Inertia 2, Vite 7, Tailwind, Laravel Echo + pusher-js |
| Real-time    | **Pusher** (managed) — swappable for self-hosted **Reverb**  |
| Database     | **MySQL** in production · SQLite for zero-config local dev    |
| AI agents    | Voiceflow Dialog Manager API (server-proxied)                |

## Why Pusher (and how to switch to Reverb)

Both Pusher and Laravel Reverb speak the **same Pusher protocol**, so the
frontend (Laravel Echo) is identical either way — switching is an env change,
not a rewrite.

- **Pusher (current default):** managed, zero-ops, nothing to host. Ideal while
  developing from an ephemeral environment. Payloads transit Pusher's cloud
  (ephemeral, not stored); for strict data residency, use an EU cluster + DPA.
- **Reverb (self-hosted):** full data ownership, no per-message fees. Switch by
  running `php artisan install:broadcasting --reverb`, setting
  `BROADCAST_CONNECTION=reverb` and the `REVERB_*` vars (placeholders already in
  `.env.example`), then `php artisan reverb:start`.

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# Local dev uses SQLite (zero-config). For MySQL, flip DB_CONNECTION=mysql
# in .env and fill DB_* — migrations are MySQL-compatible.
touch database/database.sqlite
php artisan migrate

npm run build               # or: npm run dev
php artisan serve
```

Run the queue worker so broadcasts are delivered (broadcasts are queued):

```bash
php artisan queue:work
```

### Going live with real-time

1. Create a free app at <https://dashboard.pusher.com>.
2. Fill `PUSHER_*` in `.env` and set `BROADCAST_CONNECTION=pusher`.
3. `npm run build` (Vite bakes the `VITE_PUSHER_*` values into the client).
4. Open `/dashboard` in two browsers and click **Fire a live tick** — both
   update instantly with no refresh.

> In this repo's default `.env`, `BROADCAST_CONNECTION=log` so the app boots
> without real credentials; the dashboard shows an **OFFLINE** badge until you
> add Pusher keys.

## Real-time architecture

```
Browser (Vue 3 + Inertia + Echo)
   |  ^ live ticks (WebSocket)
   v  |
Laravel (Jetstream) --proxy--> Voiceflow general-runtime (Dialog Manager API)
   |  - Controllers / VoiceflowService (API key stays server-side)
   |  - Events -> broadcast on channels
   |  - Queue worker delivers broadcasts
   v
Pusher/Reverb (WebSocket) + MySQL + (Redis for queue/cache in prod)
```

- **Events** implement `ShouldBroadcast` (see `app/Events/DashboardTick.php`).
- **Frontend** subscribes via the `useEcho` composable
  (`resources/js/composables/useEcho.js`) and patches state in place — no
  polling, no reloads.

## Voiceflow integration (server-proxied Dialog Manager API)

Configured via `.env` (`VOICEFLOW_API_KEY` — a `VF.DM.*` key from
Voiceflow → Settings → API keys, kept server-side only):

- **Runtime / Dialog Manager** — `POST {VOICEFLOW_RUNTIME_URL}/state/user/{userID}/interact`
  launches and advances conversations and injects/reads variables (the lead
  capture mechanism).
- **Transcripts API** — `{VOICEFLOW_API_URL}/v2/transcripts/{projectID}` for
  full conversation records / audit.
- **Knowledge Base API** — feed qualification docs to the agent.
- **Custom Actions** — let the agent POST a qualified lead to a Laravel webhook
  the instant it's captured.

## Build roadmap

- [x] **Phase 1** — Scaffold Laravel 12 + Jetstream (Inertia/Vue, teams), MySQL-ready.
- [x] **Phase 2** — Real-time backbone (Pusher + Echo + queue) with a live-tick demo.
- [ ] **Phase 3** — Lead domain: models/migrations, list + kanban, live status changes.
- [ ] **Phase 4** — Voiceflow proxy: `VoiceflowService`, chat panel, variable capture -> leads.
- [ ] **Phase 5** — Delegation engine: assignment rules, presence, rep-scoped views.
- [ ] **Phase 6** — Capture webhook + Transcripts backfill.
- [ ] **Phase 7** — Analytics widgets, notifications, tests, deploy docs.

## Deployment

The app is host-agnostic. Recommended targets:

- **Laravel Cloud** — first-party, fully managed (autoscaling, managed MySQL,
  queues), zero ops.
- **Laravel Forge + VPS** (e.g. Hetzner EU / DigitalOcean) — full data
  ownership, low cost; Forge manages deploys, TLS, and queue workers.
- **Railway / Render / Fly.io** — quick Dockerfile-based PaaS deploys.

Whichever you pick, run a persistent `queue:work` process (broadcasts depend on
it) and a scheduler.

## Testing

```bash
php artisan test
```
