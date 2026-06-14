---
description: Run the Update Inspector (composer + pnpm outdated) and turn the raw list into a prioritized, risk-assessed upgrade plan. Free — runs inside this interactive Claude Code session, no claude --print.
---

You are the Hermes Update Coordinator for automation_dashboard. Execute this workflow end-to-end inside the current interactive session — do NOT shell out to `claude --print` or `claude -p`.

## Step 1 — Run the collector

```
bash scripts/agents/update_inspector.sh
```

Produces `data/agents/update-inspector/findings.json` with PHP + JS outdated counts plus per-package current/latest versions. Read the JSON.

## Step 2 — Risk-bucket every package

For each outdated package, classify into one of:

- **PATCH** — z-bump (e.g. `1.2.3 → 1.2.5`). Almost always safe; bundle these together.
- **MINOR** — y-bump (`1.2.x → 1.3.0`). Read the changelog for breaking changes (semver in PHP world is occasionally violated). Usually safe but verify any package you actually use.
- **MAJOR** — x-bump (`1.x → 2.0`). Treat as a project. Read the upgrade guide. Estimate test surface.
- **LARAVEL-ECOSYSTEM** — anything Laravel framework / Inertia / Jetstream / Sanctum / Scout / Fortify. Major bumps here cascade; defer until you can dedicate a session.
- **SECURITY** — a known CVE motivates this update. Highest priority regardless of bump size. Cross-reference with `data/agents/audit-sentinel/composer_audit.log` and `pnpm_audit.log` if they exist.

For each package, fetch and read its release notes if needed:
- PHP: `https://github.com/<vendor>/<repo>/releases` (use WebFetch)
- JS: `https://github.com/<owner>/<repo>/releases` or `https://www.npmjs.com/package/<name>`

Only fetch when the bump is MAJOR or you're uncertain — don't burn time on patch bumps for unused transitive deps.

## Step 3 — Build the upgrade plan

Sort the packages into an ordered execution plan:

1. **Security bumps first** (regardless of size)
2. **All PATCH bumps as one batch** (one PR, run `composer hermes` before/after)
3. **MINOR bumps grouped by package family** (Laravel framework MINOR is special — group separately)
4. **MAJOR bumps as individual mini-projects**, each with: upgrade guide URL, expected test surface, rollback plan

For each step note:
- Command to run (e.g., `composer update vendor/package --with-dependencies`)
- Verification (e.g., `composer hermes && composer hermes-fleet-fast`)
- Risk if it breaks (which features stop working)

## Step 4 — Write the session note

Path: `docs/hermes/UPDATE-<YYYY-MM-DD_HH-MM>.md` (UTC).

Format:

```markdown
---
date: <YYYY-MM-DD>
type: update
overall: <PASS|WARN|FAIL>
php_total: <N>
js_total: <N>
major_bumps: <N>
tags: [hermes, update]
---
# Update Plan — <YYYY-MM-DD HH:MM UTC>

## Snapshot
- PHP outdated: <N> (<M> major-bump)
- JS outdated: <N> (<M> major-bump)
- Security bumps surfaced: <list or "none">

## Upgrade plan

### Step 1 — Security
<packages + command>

### Step 2 — Patch batch
<package list + one composer/pnpm command>

### Step 3 — Minor batch
<grouped by family>

### Step 4 — Major bumps (each is its own session)
#### vendor/package: <current> → <latest>
- Upgrade guide: <URL>
- Breaking changes that matter for this project: <list>
- Test surface: <which composer hermes-fleet specialists are most relevant>
- Estimated effort: <S / M / L>

## Deferred / blocked
<packages that shouldn't be updated yet — pinned for a reason, blocked by another package, etc.>
```

## Step 5 — Update the index

Edit `docs/hermes/index.md`: under the appropriate `### YYYY-MM` heading, prepend:

```markdown
- YYYY-MM-DD HH:MM UTC — [[UPDATE-YYYY-MM-DD_HH-MM|Update plan]] — <N> packages, <M> major, security: <yes/no>
```

## Step 6 — Embed visualization

Append to the UPDATE note:

```markdown
## Update plan visualization

\`\`\`mermaid
graph LR
    Collector[Update Inspector collector] --> Bucket[Risk-bucket each package]
    Bucket --> Sec[Security]
    Bucket --> Patch[Patch batch]
    Bucket --> Minor[Minor batch]
    Bucket --> Major[Major: individual sessions]
    Bucket --> Defer[Deferred / blocked]

    classDef sec fill:#fee2e2,stroke:#ef4444
    classDef batch fill:#d4f4dd,stroke:#34a853
    classDef warn fill:#fef3c7,stroke:#f59e0b

    class Sec sec
    class Patch batch
    class Minor batch
    class Major warn
    class Defer warn
\`\`\`
```

## Step 7 — Regenerate dashboard

```bash
composer hermes-dashboard
```

## Step 8 — Final report

End with ONE LINE: `update plan written, <N> total / <M> major / security: <yes/no>, docs/hermes/UPDATE-<ts>.md`.

## What you do NOT do

Do not actually run `composer update` or `pnpm update`. This command produces the plan; the human reviews and executes. Auto-updating dependencies without review is exactly how things break silently.

## Notes on cost

Same as the rest of the slash commands — every tool call runs inside the current interactive Claude Code session, subscription billing, no credit pool burn. WebFetch is fine for release notes (interactive HTTP).
