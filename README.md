# Automation Dashboard

A real-time lead-delegation dashboard built with **Laravel 12 + Inertia 2 + Vue 3
(Jetstream, teams)**. Every change — incoming leads, agent conversations,
qualification status, and delegation — ticks **live across all connected
screens** over WebSockets. Lead conversations are handled by the **native
Flowstack runtime** (Anthropic + RAG — see docs/runtime-native.md), proxied
server-side through Laravel so provider API keys never reach the browser.

## Stack

| Layer        | Choice                                                        |
| ------------ | ------------------------------------------------------------ |
| Backend      | Laravel 12, PHP 8.2+                                          |
| Auth / UI    | Jetstream (Inertia + Vue 3), teams enabled, Sanctum          |
| Frontend     | Vue 3, Inertia 2, Vite 7, Tailwind, Laravel Echo + pusher-js |
| Real-time    | **Pusher** (managed) — swappable for self-hosted **Reverb**  |
| Database     | **MySQL** in production · SQLite for zero-config local dev    |
| AI agents    | Native runtime (Anthropic + OpenAI embeddings)              |

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

This project uses **pnpm** for frontend dependencies.

```bash
composer install
pnpm install
cp .env.example .env
php artisan key:generate

# Local dev uses SQLite (zero-config). For MySQL, flip DB_CONNECTION=mysql
# in .env and fill DB_* — migrations are MySQL-compatible.
touch database/database.sqlite
php artisan migrate

pnpm run build              # or: pnpm run dev
php artisan serve
```

Run the queue worker so broadcasts are delivered (broadcasts are queued):

```bash
php artisan queue:work
```

### Going live with real-time

1. Create a free app at <https://dashboard.pusher.com>.
2. Fill `PUSHER_*` in `.env` and set `BROADCAST_CONNECTION=pusher`.
3. `pnpm run build` (Vite bakes the `VITE_PUSHER_*` values into the client).
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
Laravel (Jetstream) --calls--> Native runtime (app/Runtime: AgentRuntime + LLM providers)
   |  - Controllers bill credits around the engine (provider keys stay server-side)
   |  - Events -> broadcast on channels
   |  - Queue worker delivers broadcasts
   v
Pusher/Reverb (WebSocket) + MySQL + (Redis for queue/cache in prod)
```

- **Events** implement `ShouldBroadcast` (see `app/Events/DashboardTick.php`).
- **Frontend** subscribes via the `useEcho` composable
  (`resources/js/composables/useEcho.js`) and patches state in place — no
  polling, no reloads.

## Native runtime (server-side AgentRuntime)

Configured via `.env` (`ANTHROPIC_API_KEY` for the chat loop, `OPENAI_API_KEY`
for KB embeddings — kept server-side only):

- **Conversational engine** — `app/Runtime` (`AgentRuntime`, `FlowExecutor`,
  `LlmRouter` + per-provider clients) launches and advances conversations and
  tracks per-visitor session state. Exposed to the UI via the chat panel; the
  `capture_lead` tool upserts a `Lead` inside the engine.
- **Lead capture** — happens inside the engine (`capture_lead` tool → `leads`
  table + `LeadSaved` broadcast), so a qualified lead lands on the kanban the
  instant it's captured. No inbound engine webhooks.
- **Knowledge Base** — per-agent docs are chunked, embedded, and retrieved as
  auto-RAG context for each turn.

See `docs/runtime-native.md` for the full breakdown.

## Build roadmap

- [x] **[[phase-1-foundation|Phase 1]]** — Scaffold Laravel 12 + Jetstream (Inertia/Vue, teams), MySQL-ready.
- [x] **[[phase-2-realtime|Phase 2]]** — Real-time backbone (Pusher + Echo + queue) with a live-tick demo.
- [x] **[[phase-3-leads|Phase 3]]** — Lead domain: model/migration, kanban board, drag-and-drop + live status changes.
- [x] **Phase 5** — Conversational agent: server-side chat panel, in-engine lead capture, and live lead delivery. The current engine is the native runtime ([[runtime-native|docs/runtime-native.md]]); the original Phase 5 build ran on the legacy engine, since removed.
- [ ] **Phase 6** — Delegation engine: assignment rules, presence, rep-scoped views.
- [ ] **Phase 7** — Transcripts API backfill, analytics widgets, notifications.

> A standalone deploy command (push + Laravel Forge) lives in `bin/deploy.sh`;
> see [[phase-4-deploy|docs/phase-4-deploy.md]].

## Deployment (Laravel Forge)

This project deploys to **Laravel Forge**. One command pushes your committed
work and triggers a Forge deployment:

```bash
bin/deploy.sh          # push current branch + trigger Forge deploy
# or
composer deploy
```

Other flags:

```bash
bin/deploy.sh --no-deploy        # push only, don't deploy
bin/deploy.sh --branch main      # push a specific branch
bin/deploy.sh --status           # poll Forge API for the deploy result
```

### One-time setup

1. **Server config** — paste `deploy/forge-deploy.sh` into your Forge site's
   **Deploy Script** box (Site → Apps). Set the site's Git branch to the branch
   you deploy. Add a queue worker daemon (broadcasts depend on it) under
   Site → Queue or Server → Daemons.
2. **Deploy hook** — copy `.forge-deploy.example` to `.forge-deploy`
   (gitignored) and paste your site's **Deploy Hook** URL (Site → Apps → "Deploy
   Hook"). Alternatively export `FORGE_DEPLOY_HOOK`. The secret never enters git.
3. *(Optional)* for `--status`, export `FORGE_API_TOKEN`, `FORGE_SERVER_ID` and
   `FORGE_SITE_ID`.

`bin/deploy.sh` pushes existing commits (it never commits for you), retries the
push with backoff on transient network errors, warns if your tree is dirty, then
pings the deploy hook so Forge runs the server-side deploy script (pull →
composer → pnpm build → migrate → cache → `queue:restart`).

> **Other hosts:** the app is host-agnostic — Laravel Cloud, Railway, Render and
> Fly.io all work too. Whichever you pick, run a persistent `queue:work` process
> and a scheduler.

## Testing

```bash
php artisan test
```
