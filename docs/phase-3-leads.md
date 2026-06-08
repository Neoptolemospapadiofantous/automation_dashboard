# Phase 3 — Lead pipeline (live kanban board)

The first real domain feature: a team-scoped lead pipeline rendered as a kanban
board that updates live across every connected browser, broadcasting over the
[[phase-2-realtime|Phase 2 pipeline]].

## What this phase delivers

- **`LeadStatus` enum** — the lifecycle `new → engaging → qualified → assigned →
  won | lost`, with labels/colours for the board.
- **`leads` table + `Lead` model** — team-scoped, assignable to a rep, with
  `score`, `source`, free-form `notes`, and Voiceflow fields
  (`voiceflow_user_id`, `captured` JSON) ready for Phase 4.
- **`LeadController`** — team-scoped index, store, update, drag-and-drop status
  change, and destroy. Every mutation **broadcasts** `LeadSaved` / `LeadDeleted`
  on a **private `team.{id}` channel** (only team members may subscribe).
- **Vue kanban board** (`Leads/Index.vue`) — drag-and-drop between columns, a
  create modal, per-card score/assignee/source, and a LIVE/OFFLINE indicator. It
  subscribes via `useEcho` and patches cards in place — no reload.
- **`LeadFactory` + seeder** — 18 demo leads spread across the pipeline.
- **Feature tests** — team scoping, create/status/delete broadcasts, cross-team
  403.

## Key files

| File | Purpose |
| ---- | ------- |
| `app/Enums/LeadStatus.php` | Pipeline lifecycle. |
| `app/Models/Lead.php`, migration | Team-scoped lead record. |
| `app/Events/LeadSaved.php`, `LeadDeleted.php` | Private-channel broadcasts. |
| `app/Http/Controllers/LeadController.php` | CRUD + drag-and-drop status. |
| `routes/channels.php` | `team.{id}` channel authorization. |
| `resources/js/Pages/Leads/Index.vue`, `Components/LeadCard.vue` | Kanban UI. |
| `tests/Feature/LeadTest.php` | Scoping + broadcast tests. |

## Verify

```bash
php artisan migrate
php artisan test --filter=LeadTest
php artisan migrate:fresh --seed   # board populated; log in as test@example.com
pnpm run build
```

The board works without real-time (drag-drop persists via Inertia). Live
cross-browser updates require Pusher credentials + a running `queue:work`.

## Next

[[phase-5-voiceflow|Phase 4 adds the Voiceflow proxy]]: [[phase-5-voiceflow|conversations capture variables that
create/update leads]], which then tick onto this board live.
