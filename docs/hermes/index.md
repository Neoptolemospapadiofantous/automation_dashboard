---
type: index
tags: [hermes, index]
---

# Hermes — index

Entry point for everything CI + agent-related. For the operational doc (commands, billing, policy) see [[README]].

## Commands

### Free local CI (no LLM)
| Command | Purpose |
|---|---|
| `composer hermes` | Full local CI gate (pint, PHPStan, tests, config, routes, migrations, composer audit, vite, pnpm audit) |
| `composer hermes-fast` | Same as above, skip vite + pnpm audit |
| `composer hermes-audit` | Audit Sentinel — security/risk surface scan (CVEs, .env drift, debug routes, leaked secrets, throttle gaps) |
| `composer hermes-update` | Update Inspector — composer + pnpm outdated, major-bump count, per-package current/latest |
| `composer hermes-system` | System Check — disk, log size, queue depth, DB + Typesense ping, scheduler heartbeat |
| `composer hermes-all` | Chains all 4 free collectors above; emits aggregate verdict at the end |
| `composer hermes-status` | Read-only snapshot — last collector verdicts, last lifecycle, git position, baseline state. Reads disk only |
| `composer hermes-dashboard` | Regenerates `docs/hermes/dashboard.md` — Mermaid timelines + verdict tally + session links across all session types |

### Free interactive agents (subscription billing, no `claude --print`)
| Command | Purpose |
|---|---|
| `/hermes-fleet` | 5-agent deep audit + auto-fixes (route-auditor, inertia-page-scanner, migration-watcher, voiceflow-surface-sentinel, doc-syncer) |
| `/hermes-fleet-plan` | Same fleet but writes a reviewable plan to `data/agents/fleet/<TS>/PLAN.md` instead of applying. Pair with `/hermes-fleet-apply <TS>`. |
| `/hermes-fleet-apply <TS>` | Apply a previously-written plan after operator review. Refuses if working tree dirty; rolls back if `composer hermes-fast` regresses. |
| `/hermes-docs` | Single-agent doc-sync against current code |
| `/hermes-audit` | Runs `composer hermes-audit` then interprets findings, classifies real vs false positive, proposes fixes |
| `/hermes-update` | Runs `composer hermes-update` then builds a risk-bucketed upgrade plan with release-notes lookups |
| `/hermes-system` | Runs `composer hermes-system` then digs into anomalies (top errors, queue patterns, recommended actions) |
| `/hermes-lifecycle` | **Full 8-phase project lifecycle** — scan → analysis → docs → obsidian → flowcharts → tests → validate → commit. Each phase coordinates with the prior. Never pushes. ~30-60 min wall-clock. |
| `/loop <interval> /hermes-lifecycle` | Run the lifecycle on a recurring interval (e.g. `/loop 4h /hermes-lifecycle`). All iterations stay in the same interactive session — subscription billing, no credit pool. `/loop stop` to end. |
| `/hermes-status` | Read-only snapshot + short interpretation + recent-sessions Mermaid timeline. Reads disk only — no scans, no edits |

## Visualization

Each session note (LIFECYCLE / FLEET / DOCS / AUDIT / UPDATE / SYSTEM) now embeds a Mermaid diagram showing its internal flow with status colors.

The cross-session dashboard at [[dashboard]] is auto-regenerated at the end of every session and aggregates Mermaid timelines + verdict tallies across all session types. Open it in Obsidian to see the bird's-eye view.

### Planned (not yet wired)
| Command | Purpose |
|---|---|
| `/hermes-obsidian` | Standalone vault hygiene pass (subset of Phase 4 in `/hermes-lifecycle`) |
| `/hermes-flowcharts` | Standalone Mermaid regeneration (subset of Phase 5 in `/hermes-lifecycle`) |

### Two-tier model
Every concern that has an agent slash command also has a no-LLM collector. The collector produces `data/agents/<name>/findings.json`; the slash command reads that JSON plus broader context and gives an expert interpretation. Run the collector when you just want the data fast; run the slash command when you want judgment.

## Agent session notes

Every agent run writes a session note here with frontmatter for [[#Search by frontmatter|dataview queries]]. Naming convention: `<TYPE>-<YYYY-MM-DD>_<HH-MM>.md`.

### 2026-06

- 2026-06-18 21:11 UTC — [[AUDIT-2026-06-18_21-11|Audit]] — WARN, 0 critical / 0 high / 2 medium confirmed
- 2026-06-14 15:35 UTC — [[AUDIT-2026-06-14_15-35|Audit]] — PASS, 0 critical / 0 high / 0 medium confirmed
- 2026-06-08 16:55 UTC — [[FLEET-2026-06-08_16-55|Fleet run]] — baseline PASS → final PASS, 10 fixes applied (9 throttle, 1 swallowed-exception), 4 doc-sync edits absorbed
- 2026-06-05 15:41 UTC — [[FLEET-2026-06-05_15-41|Fleet run]] — baseline WARN → final WARN, 8 fixes applied (route throttles, a11y, dead imports)
- 2026-06-05 14:15 UTC — [[FLEET-2026-06-05_14-15|Fleet run]] — first fleet run, same 8 fixes

## Frontmatter convention

All agent session notes use this YAML header so Obsidian's search + dataview can filter cleanly:

```yaml
---
date: YYYY-MM-DD
type: fleet | docs | obsidian | flowcharts
baseline_overall: PASS | WARN | FAIL   # fleet only
final_overall: PASS | WARN | FAIL      # fleet only
overall: PASS | WARN | FAIL            # single-agent jobs (docs/obsidian/flowcharts)
agents: [agent-name, ...]              # which specialists ran
tags: [hermes, <type>]
---
```

Naming convention: `<TYPE>-<YYYY-MM-DD>_<HH-MM>.md` where `<TYPE>` is `FLEET`, `DOCS`, `AUDIT`, `UPDATE`, `SYSTEM`, `LIFECYCLE`, `OBSIDIAN`, or `FLOWCHARTS`.

### Search by frontmatter

In Obsidian, search `tag:#hermes` to see all runs. Refine with `["type":"fleet"]` for fleet-only.

## Static-analysis baseline

[[../../phpstan-baseline.neon|phpstan-baseline.neon]] captures 217 pre-existing PHPStan + shipmonk dead-code errors as of 2026-06-05. The watchdog only fails on NEW issues. Smaller baseline file over time = quality trending up.

Regenerate after a cleanup batch:

```bash
vendor/bin/phpstan analyse --generate-baseline --memory-limit=2G
```

## Related

- [[../public-surface.md|public-surface]] — public API contract, kept in sync by the fleet's doc-syncer agent
- [[../architecture/integration-map.md|integration-map]] — cross-page integration diagram, target for the planned `hermes-flowcharts` job
- [[README|hermes/README]] — operational doc, June 15 billing context, TODO checklist
