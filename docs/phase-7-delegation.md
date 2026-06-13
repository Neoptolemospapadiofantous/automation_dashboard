# Phase 7 — Lead delegation engine

Route qualified leads to reps automatically (or by hand), with a full audit
trail, rep-scoped views, and live updates — the "lead delegation" half of the
original goal.

## What shipped

- **`AssignmentStrategy` enum**: `round_robin`, `least_loaded`, `manual`,
  `unassigned`.
- **`lead_assignments` table + model**: an audit row per (re)assignment
  recording who/what assigned the lead, to whom, from whom, and via which
  strategy.
- **`LeadDelegator` service**:
  - **round_robin** — the member assigned least recently (never-assigned first),
    derived from the audit log.
  - **least_loaded** — the member with the fewest open (assigned/qualified)
    leads.
  - **manual** — an explicit target member.
  - Updates the lead, auto-advances status to `assigned`, records the audit row,
    all in one transaction.
- **Auto-delegation**: the [[phase-5-voiceflow|conversational-engine capture webhook]] round-robins a freshly
  **qualified, unassigned** lead the instant the agent qualifies it.
- **`POST /leads/{lead}/assign`** endpoint (team-scoped, [[phase-3-leads|broadcasts `LeadSaved`]]).
- **[[phase-3-leads|Board UI]]**: each card has an assignee dropdown (Unassigned / ⟳ Auto-assign /
  a specific member); a **My leads / All leads** toggle filters to the current
  user's leads (`?mine=1`).

## Key files

| File | Purpose |
| ---- | ------- |
| `app/Enums/AssignmentStrategy.php` | Strategies. |
| `app/Models/LeadAssignment.php`, migration | Audit trail. |
| `app/Services/LeadDelegator.php` | Assignment logic. |
| `app/Http/Controllers/LeadController.php` | `assign()` + `?mine` filter. |
| the legacy capture-webhook controller | Auto-delegate on qualify. |
| `resources/js/Components/LeadCard.vue`, `Pages/Leads/Index.vue` | Assign UI + My-leads. |
| `tests/Feature/LeadDelegationTest.php` | Strategy + endpoint + scoping tests. |

## Verify

```bash
php artisan migrate
php artisan test --filter=LeadDelegationTest
pnpm run build
```

## Notes

- Assignments broadcast `LeadSaved` on the private team channel, so every open
  board reflects the new owner live.
- Round-robin and least-loaded both operate on `team->allUsers()`, so they
  respect [[phase-1-foundation|Jetstream team membership]].

## Next ideas

- Presence (who's online) to skip offline reps in round-robin.
- Per-team default strategy + assignment rules (by score/source).
- Notifications to the assigned rep.
