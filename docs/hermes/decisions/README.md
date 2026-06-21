---
type: index
tags: [hermes, decisions, architecture]
---

# Decision notes

Durable rationale for architectural and integration choices. Read by the fleet coordinator + lifecycle Phase 2 before applying any auto-fix — a finding that contradicts an indexed decision surfaces as `challenges decision <slug>` instead of getting auto-applied.

## How a decision differs from a suppression

| | Decision (this dir) | Suppression (`.hermes/suppressions.yaml`) |
|---|---|---|
| Scope | Pattern / architectural choice | One finding signature |
| Form | Prose with context + alternatives rejected | YAML row with reason + expiry |
| Use when | "We chose X because Y; rejected Z" | "This specific file:line is fine" |
| Coordinator effect | Surfaces conflict as "challenges decision" | Skips the finding silently |
| Lifecycle | Lives forever unless decision changes | Has `expires:` field |

## Naming convention

`YYYY-MM-DD-kebab-case-slug.md`. The date is when the decision was MADE, not when the note was written. Frontmatter:

```yaml
---
date: YYYY-MM-DD
type: decision
status: active | superseded | deprecated
tags: [hermes, decisions, <topic>]
supersedes: <prior-slug, if applicable>
---
```

Body structure: **Context → Decision → Rationale → Alternatives rejected → Consequences**.

## Current decisions

### Voiceflow wrapper architecture

- [[2026-06-08-no-svix-composer-dep|2026-06-08]] — implement Svix HMAC verification in-house rather than add `svix/svix` dep
- [[2026-06-08-streaming-uses-fetch|2026-06-08]] — frontend chat streaming uses `fetch + ReadableStream`, not `EventSource`
- [[2026-06-08-workspace-key-falls-back-to-dm|2026-06-08]] — workspace API key resolution falls back to DM key for backwards compat
- [[2026-06-08-getvariables-30s-cache|2026-06-08]] — `getVariables()` caches for 30 seconds per `(project, user)`
- [[2026-06-08-typed-subclients-not-monolith|2026-06-08]] — Voiceflow API is wrapped via per-host typed subclients, not a monolithic service

### Hermes safety system

- [[2026-06-08-no-cron-for-fleet|2026-06-08]] — fleet runs are never scheduled; always interactive or explicit
- [[2026-06-08-suppressions-yaml-not-baseline|2026-06-08]] — fleet suppressions use YAML with `expires:`, not a baseline file with no review
- [[2026-06-14-learning-is-periodic-not-automated|2026-06-14]] — the learning loop (increment 6) is periodic + human-reviewed, never automated; extends the no-cron decision
- [[2026-06-21-onboarding-state-granular-check|2026-06-21]] — `app/Lifecycle` declares an `onboarding-state` granular check (locks in escaped fix `6fce398`); manifest localization, no new test

## When to write one

You're writing a decision note when:
- The fleet just flagged something you'd rather not re-explain every quarter
- A future reader would ask "why did they do it this way?"
- A reasonable alternative existed and you chose against it
- An external constraint (Voiceflow API, Anthropic billing, browser support) drove the choice

You're NOT writing one when:
- The reason is obvious from a glance at the code
- It's a one-line `@hermes-keep:` annotation territory
- The decision will be revisited in a week
