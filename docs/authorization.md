# Authorization — who can do what, and where it's enforced

> The matrix lives in one place (`app/Authorization/Role.php`); everything
> else delegates to it. The code is the source of truth — this doc just
> explains the shape and the gaps.

Authentication is stock Jetstream + Fortify + Sanctum (session guard `web`,
Jetstream's own `guard` is `sanctum`; see `config/auth.php`,
`config/jetstream.php`). This doc is about **authorization** — once you're
logged in and belong to a team, what are you allowed to do? See also
[[phase-13-multitenancy]] for how teams scope data.

## Two layers, two jobs

1. **Jetstream team roles** (`admin` / `editor`) — the raw membership role
   stored on the `team_user` pivot, registered in
   `app/Providers/JetstreamServiceProvider.php`. These carry CRUD-ish
   permission strings (`create`/`read`/`update`/`delete`) but the app does
   **not** check those permission strings directly anywhere.
2. **The `Role` capability enum** (`app/Authorization/Role.php`) — the real
   decision layer. It wraps the Jetstream role plus team-ownership into four
   cases with explicit `can*()` methods. This is what controllers consult.

The `TeamPolicy` (`app/Policies/TeamPolicy.php`) is a third, narrower layer
that only governs Jetstream's own team-management screens.

## The roles (`app/Authorization/Role.php`)

`Role::forUser($user, $team)` resolves a user's effective role on a team:

| Role     | How it's derived                                              |
| -------- | ------------------------------------------------------------- |
| `Owner`  | `$team->user_id === $user->id` (Jetstream stores the owner FK as `user_id`, not `owner_id`) |
| `Admin`  | invited member whose Jetstream role key is `admin`            |
| `Editor` | invited member whose Jetstream role key is `editor`          |
| `Member` | belongs to the team but has no Jetstream role set             |

The capability matrix (the `can*()` methods):

| Capability         | Owner | Admin | Editor | Member | Gated action                          |
| ------------------ | :---: | :---: | :----: | :----: | ------------------------------------- |
| `canDeleteAgent`   |  ✔   |       |        |        | delete agent, rotate webhook secret   |
| `canUpdateAgent`   |  ✔   |  ✔   |        |        | rename / switch / edit agent behavior |
| `canCreateAgent`   |  ✔   |  ✔   |        |        | create a new agent (plan-limited)     |
| `canDeleteLead`    |  ✔   |  ✔   |        |        | delete kanban leads (mass-destructive)|
| `canDeleteKnowledge`| ✔   |  ✔   |        |        | delete KB documents                   |
| `canAddKnowledge`  |  ✔   |  ✔   |   ✔   |        | add KB docs (URL / file / text)       |
| `canManageLeads`   |  ✔   |  ✔   |   ✔   |        | create / update leads                 |

Design intent (from the enum's own comments): **Owner-only** = money,
identity, or killing the agent. **Admin+** = destroys data without billing
impact. **Editor+** = creates/modifies content. **Member** = read-only + chat.
Billing actions (subscribe, top-up, manage subscription) are Owner-only and
checked separately via `requireOwner` — there is no `can*()` method for them.

## How it's enforced — `AuthorizesByTeamRole`

The primary mechanism is **not** Laravel policies and **not** `can:` route
middleware. It's a controller trait:
`app/Http/Controllers/Concerns/AuthorizesByTeamRole.php`. It exposes:

- `requireOwner($request, $action)` — owner-only gate.
- `requireCapability($request, fn (Role $r) => …, $action)` — arbitrary
  capability gate; `$action` is a verb-phrase baked into the 403 message
  ("Your role (Editor) doesn't permit you to delete an agent.").

Both resolve `Role::forUser($user, $user->currentTeam)` and `abort(403, …)`
on failure. No current team ⇒ 403 outright.

Real call sites:

- `app/Http/Controllers/AgentController.php:73,95,111` — create / update / delete agent.
- `app/Http/Controllers/AgentVersionsController.php:85,121,162` — draft / publish / restore behavior (all `canUpdateAgent`).
- `app/Http/Controllers/KnowledgeBaseController.php:96,130,169,217` — add URL / file / text, delete.
- `app/Http/Controllers/LeadController.php:203,251` — create (`canManageLeads`), delete (`canDeleteLead`).
- `app/Http/Controllers/SubscribeController.php:36` — subscribe (`requireOwner`).
- `app/Http/Controllers/BillingController.php:94,142` — top-up, manage subscription (`requireOwner`).

The UI mirror: `HandleInertiaRequests` ships an `is_owner` flag in the
shared `billing` prop (`app/Http/Middleware/HandleInertiaRequests.php:115`)
so non-owners don't see buttons that would 403. That's cosmetic — the
server-side `requireOwner` is the real gate.

## `TeamPolicy` — Jetstream team management only

`app/Policies/TeamPolicy.php` is auto-discovered by Laravel (no explicit
registration in any provider; the `Team` model → `TeamPolicy` naming
convention does it). It gates Jetstream's built-in team CRUD and the
team-settings page, **not** the agent/KB/lead features above.

| Method             | Ability string (Jetstream) | Rule                  |
| ------------------ | -------------------------- | --------------------- |
| `viewAny`          | —                          | always `true`         |
| `view`             | `view`                     | `$user->belongsToTeam` |
| `create`           | `create`                   | always `true`         |
| `update`           | `update`                   | `$user->ownsTeam`     |
| `addTeamMember`    | `addTeamMember`            | `$user->ownsTeam`     |
| `updateTeamMember` | `updateTeamMember`         | `$user->ownsTeam`     |
| `removeTeamMember` | `removeTeamMember`         | `$user->ownsTeam`     |
| `delete`           | `delete`                   | `$user->ownsTeam`     |

Note these are **owner-only** in practice — an `admin` team member cannot
add/remove members or rename the team through Jetstream, despite holding the
`admin` Jetstream role with a `delete` permission string. The permission
strings are registered but unused by these methods.

## Team scoping (the implicit authz layer)

Most data is protected not by a role check but by **team scoping**: every
query is rooted at `$request->user()->currentTeam` and its relations, so a
user only ever sees their own team's agents, leads, KB docs, and
conversations. `current_team_id` lives on the `users` table (Jetstream
`HasTeams`); the `team_user` pivot uses the custom `Membership` model
(`app/Models/Membership.php`, only override: auto-incrementing IDs).

Belt-and-suspenders ownership checks appear where a route binds a model by
ID, e.g. `app/Http/Controllers/LeadController.php:302`
(`abort_unless($lead->team_id === $request->user()->currentTeam->id, 403)`).
This guards against a member of team A passing team B's lead ID.

`RequireAgent` middleware (`routes/web.php:37`) is an onboarding gate, not an
authz gate — it forces users with no active agent into the wizard.

## Known gaps / what is NOT enforced

- **Reads are role-blind.** Any team member (including `Member`) can view
  agents, leads, KB, conversations, and analytics. Authorization for reads
  is team-scoping only; there's no "read this lead" capability.
- **`TeamPolicy` is owner-only, ignoring Jetstream permission strings.** The
  `admin`/`editor` permission arrays in `JetstreamServiceProvider` are
  registered but never consulted (`hasTeamPermission` is not called
  anywhere). An `admin` can't manage members despite the matrix implying it.
- **Member can still chat / consume credits.** Capability checks gate
  mutations of config and content, not chat usage — by design (Member =
  "read-only + chat"), but worth knowing for billing exposure.
- **No route-level `can:` middleware.** All feature authz is in controller
  bodies via `AuthorizesByTeamRole`. A new controller action that forgets to
  call `requireCapability` is unguarded beyond team-scoping. There's no
  middleware safety net — adding the trait call is a manual, per-action step.
- **Role not broadly shared to the frontend.** Only `is_owner` reaches Inertia
  (in the billing prop). The full `Role` isn't shared, so other UI affordances
  can't pre-hide based on Admin/Editor/Member — they rely on the 403.
