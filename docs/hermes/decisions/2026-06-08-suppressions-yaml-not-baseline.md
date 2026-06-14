---
date: 2026-06-08
type: decision
status: active
tags: [hermes, decisions, safety]
---

# Fleet suppressions use YAML with `expires:`, not a baseline file

## Context

After the Tier 1 safety system landed, the fleet needs a way to record "this finding was reviewed and is intentional". Two patterns exist in the broader ecosystem:

1. **PHPStan-style baseline** — opaque file (`phpstan-baseline.neon`) listing every existing error; new errors fail, baseline absorbs nothing
2. **YAML allowlist with rationale + expiry** — `.hermes/suppressions.yaml` with `finding`, `location`, `reason`, `reviewed`, `expires`

## Decision

Go with #2 — YAML allowlist with mandatory `reason`, `reviewed`, `expires`.

## Rationale

- **Rationale lives with the suppression** — PHPStan baselines accumulate "we just turned this off"; YAML forces a prose answer to "why is this fine?"
- **Expiry forces review** — default is 1 year. After expiry, the fleet resurfaces the finding as `review-due` so it doesn't sit forever
- **Stale entries get caught** — if `location:` no longer matches a real codepath (file moved, line drift), the fleet emits `stale_suppression` and the operator can prune
- **Suppressions are PR-reviewed** — they're code-shaped (yaml in repo), so adding one is a PR conversation, not a `--ignore` flag
- **Diff with `@hermes-keep:`** — annotations are for one-line decisions visible at the code site; suppressions handle cross-cutting patterns, file globs, or things that span multiple lines

## Alternatives rejected

| Option | Why no |
|---|---|
| `.hermes-baseline.json` opaque | Loses rationale; future readers have no idea why an entry exists |
| `// @hermes-suppress` annotation on every line | Doesn't scale to cross-cutting / glob suppressions |
| Dedicated database table | Overkill for a 1-100 entry file; loses git history |
| No suppression mechanism — just write decisions | Suppressions and decisions serve different audiences (one is per-finding mechanical, the other is per-pattern human-readable) |

## Consequences

- Fleet coordinator reads `.hermes/suppressions.yaml` in Step 2 (Gather context); applies in Step 4 (Synthesize) as part of the safety filter pipeline
- Operators get fleet output like `[suppressed: <reason>]` so they see what's being skipped and why
- Year-long default expiry means every suppression gets re-confirmed annually — keeps the list current
- If the format becomes a bottleneck, migration is local — just rewrite the file

## Related

- `.hermes/suppressions.yaml` — the file itself with format documentation
- `docs/hermes/conventions.md` — when to use suppressions vs `@hermes-keep` vs decisions
- `.claude/commands/hermes-fleet.md` — coordinator instructions for applying the filter
