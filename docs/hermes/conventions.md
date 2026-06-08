---
type: reference
tags: [hermes, conventions, safety]
---

# Hermes Fleet Conventions — keeping the agents from touching what shouldn't be touched

**Five layered mechanisms** protect deliberate code patterns from drive-by "fixes" by the fleet's CRITICAL/HIGH auto-apply step. From most local to most global:

| Layer | Lives | Granularity | When to use |
|---|---|---|---|
| Recent-touch guard | automatic, no config | per-file (last 30 min) | Built-in — protects in-progress work |
| Revert-aware memory | `git log --grep='Revert.*hermes'` | per-pattern | Built-in — respects previously-rejected changes |
| `@hermes-keep:` annotation | inline in the source file | one line or block | Local, self-documenting — the why lives next to the code |
| `.hermes/suppressions.yaml` | committed at repo root | one finding signature per entry | Cross-cutting / repeated patterns; expires for forced review |
| `docs/hermes/decisions/*.md` | committed dir | architectural pattern | Big "we chose X because Y" decisions — surfaces conflicts |
| `DO-NOT-TOUCH` list | baked into `/hermes-fleet.md` + `/hermes-lifecycle.md` | whole files / globs | Hard rules that never bend (.env, migrations, billing) |

Plus a **plan/apply split**: `/hermes-fleet-plan` writes a reviewable plan without touching the working tree; `/hermes-fleet-apply <TS>` applies after operator review. Use plan mode for any uncertain run.

## 1. `@hermes-keep:` inline annotation

Self-documenting marker. The fleet treats any specialist finding whose `file:line` is within 5 lines of a `@hermes-keep:` comment as **noted, not actionable** — it goes to carry-forward, never to the auto-apply queue.

### PHP / JS — line or block comment

```php
// @hermes-keep: intentional fallback to DM key for backwards compat (Phase 13 decision)
return $this->workspaceApiKey ?: $this->apiKey;
```

```js
// @hermes-keep: standard Laravel CSRF read pattern for fetch() — not a reactivity bypass
const csrf = document.head.querySelector('meta[name="csrf-token"]')?.content;
```

### Vue templates — HTML comment

```vue
<!-- @hermes-keep: paginator labels are server-controlled HTML entities, not user input -->
<span v-html="link.label" />
```

### When to use vs. when not to

| Use `@hermes-keep:` | Use `.hermes/suppressions.yaml` instead |
|---|---|
| One specific line or small block | Cross-cutting pattern across many files |
| Self-evident from the surrounding code | Needs prose explanation longer than 1-2 lines |
| Will stay stable through refactors | Subject to review (use `expires`) |

The annotation must always include a colon-delimited `reason` after `@hermes-keep:` — no bare `@hermes-keep` with no rationale.

## 2. `.hermes/suppressions.yaml` — committed allowlist

Cross-cutting suppressions with prose rationale + expiry. See the file for format. Key invariants:

- **Every entry has an `expires` date** (or `never`). Year-long is the default; that forces an annual re-review.
- **Stale suppressions surface** — when a suppression's `location:` no longer matches a real finding, the fleet reports `stale_suppression: <entry>` so you can clean it up.
- **Globs are allowed** — `location: "app/Services/Voiceflow/**"` suppresses by directory.

The fleet coordinator reads this file in its Setup phase, before spawning specialists. Each specialist still reports the finding; the coordinator filters during synthesis.

## 3. Recent-touch guard (automatic)

The fleet's Setup phase captures `git status --porcelain` + files modified in the last 30 minutes. Any specialist finding whose file is in that list goes straight to carry-forward — never auto-applied. Rationale: if you're actively editing a file, an agent overwriting your in-progress work is the most expensive mistake the fleet can make.

This is purely behavioural; nothing to configure. Override by committing or stashing before invoking `/hermes-fleet`.

## How the layers compose

When a specialist emits a finding, the coordinator applies this pipeline in order:

```
1. Is the file in the recent-touch list (modified in last 30 min)?
       → carry-forward, never apply
2. Would the fix re-introduce something reverted in the last 30 days?
       → carry-forward, "previously-reverted: <SHA>"
3. Is the file:line within 5 lines of a @hermes-keep: comment?
       → noted, never apply, with the quoted reason
4. Does .hermes/suppressions.yaml match (exact path or glob)?
       → skipped, with `[suppressed: <reason>]`
5. Does the finding contradict a docs/hermes/decisions/*.md entry?
       → "challenges decision <slug>" in carry-forward, never auto-apply
6. Is the file in the DO-NOT-TOUCH list?
       → carry-forward + flag CRITICAL if security
7. Otherwise: normal severity rules apply
       → CRITICAL/HIGH auto-apply, MEDIUM/LOW carry-forward
```

The DO-NOT-TOUCH list (Phase 2 hard refusal) currently covers:
- `.env*`
- `config/database.php`, `config/services.php`
- `database/migrations/**`
- `app/Models/User.php`
- `bin/deploy.sh`

Plus, in Phase 3 (Docs): `docs/audit/`, `docs/phase-1-foundation.md`.

## When a fleet violates these rules

If you find the fleet edited something with a `@hermes-keep:` or in the suppressions list, that's a bug in the coordinator's filtering. File a finding into `docs/hermes/decisions/` (when that dir exists) or surface it via `/hermes-status`. The coordinator prompt may need tightening.
