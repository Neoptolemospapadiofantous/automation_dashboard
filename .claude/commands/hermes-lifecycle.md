---
description: Full 8-phase project lifecycle — static scan → agent error analysis → docs → obsidian → flowcharts → tests → validate → commit (no push). Each phase documents, validates, and coordinates with the prior. Runs entirely inside this interactive Claude Code session (free, subscription billing). Heavy session — budget ~1 hour wall-clock.
---

You are the Hermes Lifecycle Coordinator for automation_dashboard. Execute the seven phases below in strict order. Each phase MUST:

1. **Pre-validate**: read prior phase outputs, confirm expected state, abort with a clear error if something's missing.
2. **Execute**: perform the phase work.
3. **Post-validate**: confirm outputs landed, run a sanity check.
4. **Document**: write the phase output file.
5. **Update MANIFEST.md**: mark the phase row as done with a one-line result.

Do NOT skip phases. Do NOT shell out to `claude --print` or `claude -p` at any point — every LLM call must run inside the current interactive session so it bills against subscription, not the post-2026-06-15 Agent SDK credit pool.

## Setup (one-time, run before Phase 1)

Get the session timestamp and create the session dir:

```bash
TS=$(date -u +%Y-%m-%d_%H-%M)
SESSION=data/agents/lifecycle/$TS
mkdir -p $SESSION
echo "$TS" > $SESSION/SESSION_ID
# Snapshot working-tree dirtiness BEFORE any lifecycle work — Phase 8 needs it
git status --porcelain | wc -l > $SESSION/PRE_SESSION_DIRTY
git rev-parse HEAD > $SESSION/PRE_SESSION_HEAD

# Prune old lifecycle sessions — keep the 10 most recent (prevents disk fill from /loop mode)
ls -1dt data/agents/lifecycle/*/ 2>/dev/null | tail -n +11 | xargs -r rm -rf
```

**Pre-flight check** — confirm baseline gate is active:

```bash
git ls-files --error-unmatch phpstan.neon phpstan-baseline.neon
```

If either file is untracked, write a one-line warning to MANIFEST.md and surface to the user at the end of the lifecycle:

```
⚠️ PHPStan baseline file is not git-tracked. The regression gate won't span PRs until you run: git add phpstan.neon phpstan-baseline.neon && git commit
```

Do NOT auto-commit — leave the decision to the user. Continue the lifecycle either way.

Initialize MANIFEST.md:

```markdown
# Lifecycle Session — <TS>

| Phase | Step | Status | Result |
|---|---|---|---|
| 1 | Scan | pending | — |
| 2 | Analysis | pending | — |
| 3 | Docs | pending | — |
| 4 | Obsidian | pending | — |
| 5 | Flowcharts | pending | — |
| 6 | Tests | pending | — |
| 7 | Validate | pending | — |
| 8 | Commit | pending | — |

Started: <UTC datetime>
Pre-session HEAD: <recorded in PRE_SESSION_HEAD>
Pre-session dirty: <recorded in PRE_SESSION_DIRTY>
```

Write that to `data/agents/lifecycle/<TS>/MANIFEST.md`. The session dir path and TS are referenced throughout — call them `$SESSION` and `$TS` mentally.

---

## Phase 1 — Static Scan (no LLM)

**Goal**: Capture the project's current health as structured data with zero LLM cost.

### Execute (parallel via Bash)

Run in a single message:
- `composer hermes-fast` — CI gate (pint, PHPStan, tests, config, routes, migrations, composer-audit)
- `composer hermes-audit` — security/risk scan
- `composer hermes-update` — outdated deps
- `composer hermes-system` — runtime health
- `composer validate --strict --no-check-publish` — confirms `composer.json` is well-formed and `composer.lock` matches it
- `pnpm install --frozen-lockfile --lockfile-only 2>&1 | head -20` — verifies `pnpm-lock.yaml` is in sync with `package.json` (errors fast if drift). Capture exit code; non-zero = lock drift.

**Test inventory** — capture current test surface for trend comparison and Phase 6 coverage analysis:

```bash
test_files=$(find tests -name '*.php' -type f ! -name 'TestCase.php' | wc -l)
test_methods=$(grep -rE "function test_|@test\b|^\s+it\(" tests/ 2>/dev/null | wc -l)
test_pass=$(grep -oE "[0-9]+ passed" data/logs/test.log 2>/dev/null | head -1 | grep -oE "[0-9]+" || echo 0)
test_fail=$(grep -oE "[0-9]+ failed" data/logs/test.log 2>/dev/null | head -1 | grep -oE "[0-9]+" || echo 0)

cat > $SESSION/01-test-inventory.json <<EOF
{
  "test_files": $test_files,
  "test_methods": $test_methods,
  "test_pass": $test_pass,
  "test_fail": $test_fail
}
EOF
```

Record lock-file integrity into `$SESSION/01-lockfiles.json`:

```json
{
  "composer_lock_ok": <true|false>,
  "composer_validate_output": "<short>",
  "pnpm_lock_ok": <true|false>,
  "pnpm_install_output": "<short>"
}
```

If either lock file is out of sync, surface as a Phase 1 finding (overall WARN at minimum) — Phase 2 walk-through will need to handle it.

### Aggregate

After all four return, read these JSONs:
- `data/hermes_findings.json`
- `data/agents/audit-sentinel/findings.json`
- `data/agents/update-inspector/findings.json`
- `data/agents/system-check/findings.json`

Combine into `$SESSION/01-scan.json`:

```json
{
  "ts": "<TS>",
  "ci": { ... contents of hermes_findings.json ... },
  "audit": { ... contents of audit-sentinel/findings.json ... },
  "update": { ... contents of update-inspector/findings.json ... },
  "system": { ... contents of system-check/findings.json ... },
  "overall": "<derived: WORST of the four overalls>"
}
```

### Document

Write `$SESSION/01-scan.md`:

```markdown
# Phase 1 — Static Scan

**Overall**: <PASS|WARN|FAIL>

## CI watchdog
<one line: pass/warn/fail counts + which checks failed>

## Audit Sentinel
<critical/high/medium counts + top findings>

## Update Inspector
<PHP outdated total + JS outdated total + major-bump count>

## System Check
<pass/warn/fail counts + which anomalies>

## Baseline data
Full structured findings in `01-scan.json` for downstream phases.
```

### Post-validate

Confirm `$SESSION/01-scan.json` exists and parses as JSON. Confirm `$SESSION/01-scan.md` exists.

### Update MANIFEST.md

Change Phase 1 row to: `1 | Scan | done | <overall> — N CI / N audit / N update / N system findings`

---

## Phase 2 — Agent Error Analysis (LLM, parallel)

**Goal**: Spawn the 5 fleet specialists from `scripts/fleet_agents.json` to interpret Phase 1's findings, then apply CRITICAL/HIGH fixes.

### Pre-validate

Read `$SESSION/01-scan.json`. Confirm it has all four sections. If not, abort with an error written to MANIFEST.md.

### Execute — parallel agent spawn

Read `scripts/fleet_agents.json`. In ONE message containing FIVE Agent tool calls (parallel):

- `route-auditor` (subagent_type: `general-purpose`) — pass the `route-auditor.prompt` field verbatim
- `inertia-page-scanner` (subagent_type: `general-purpose`) — pass the `inertia-page-scanner.prompt` field verbatim
- `migration-watcher` (subagent_type: `general-purpose`) — pass the `migration-watcher.prompt` field verbatim
- `voiceflow-surface-sentinel` (subagent_type: `general-purpose`) — pass the `voiceflow-surface-sentinel.prompt` field verbatim
- `doc-syncer` (subagent_type: `general-purpose`) — pass the `doc-syncer.prompt` field verbatim

Collect all 5 reports.

### Walk through Phase 1 findings the specialists didn't cover

The 5 fleet specialists cover routes, Inertia, migrations, Voiceflow, and docs — but several Phase 1 audit/update/system findings have no dedicated specialist. Explicitly synthesize these:

- **`audit_sentinel.env-missing-keys`** → list which keys are in `.env.example` but missing from `.env`. Flag for Phase 3 (Docs) to evaluate whether `.env.example` itself needs trimming.
- **`audit_sentinel.debug-routes`** → if any found, classify CRITICAL and add to the fix queue.
- **`audit_sentinel.leaked-secret`** → if any found, classify CRITICAL. Do NOT auto-remove the secret from the repo (history is already poisoned); flag for emergency rotation + carry forward.
- **`audit_sentinel.debug-in-prod`** → if `.env` has `APP_ENV=production` + `APP_DEBUG=true`, classify CRITICAL and FLAG for human (do NOT auto-edit `.env` — it's in DO-NOT-TOUCH).
- **`update_inspector.*`** → no specialist covers this. As coordinator, walk the outdated list yourself: any package with a `SECURITY` flag (cross-reference `composer_audit.log`) → CRITICAL. Major bumps for Laravel/Inertia/Tailwind/Vite → carry-forward as their own session. Patch bumps → carry-forward as a batched update.
- **`system_check.*`** → no specialist covers this. As coordinator: FAILs → CRITICAL (page someone); WARNs → carry-forward with recommended action.

### Synthesize and apply

Bucket every finding (from both Phase 1 collectors AND the 5 agents AND the walk-through above) into:
- **CRITICAL** — security holes, broken auth, leaked secrets, exposed mutating endpoints, exploitable code paths
- **HIGH** — real problems, fix this PR (< 10 min, low blast radius)
- **MEDIUM** — track in note, schedule cleanup
- **LOW** — note only

For each CRITICAL/HIGH outside the safety filter, apply the fix via Edit/Write. Maintain a running list of `(file:line, what changed, why)` tuples for Phase 6.

**Safety filter — apply IN THIS ORDER to every finding before deciding to apply:**

1. **Recent-touch guard** — read `git status --porcelain | awk '{print $2}'` plus `find app routes resources -type f -mmin -30 2>/dev/null`. Any finding whose file is in this list goes to `carry_forward`, never auto-applied. User is actively editing; do not stomp in-progress work.
2. **Revert-aware** — capture `git log --oneline --grep='Revert.*hermes' --since='30 days ago'`. For each revert, find the original commit (the revert message contains the SHA). If a finding would re-introduce something whose original commit was reverted in the last 30 days, flag as `previously-reverted: <revert SHA>: <subject>` and send to `carry_forward`. The user already explicitly rejected this kind of change recently.
3. **`@hermes-keep:` annotation** — if the finding's `file:line` is within 5 lines of a `// @hermes-keep: <reason>` (PHP/JS) or `<!-- @hermes-keep: <reason> -->` (Vue) comment, send to `carry_forward` and surface as "annotated as intentional: <quoted reason>".
4. **`.hermes/suppressions.yaml` match** — read the file if it exists. If `location:` matches the finding (exact path or glob), skip entirely; record `[suppressed: <reason>]` in `carry_forward`.
5. **`docs/hermes/decisions/` conflict** — read all `docs/hermes/decisions/*.md` for active architectural decisions. If a finding contradicts an indexed decision, send to `carry_forward` as `challenges decision <slug>`. Do NOT auto-apply.
6. **HARD DO-NOT-TOUCH** (refuse to edit even if a finding suggests it):
   - `.env*`
   - `config/database.php`, `config/services.php`
   - `database/migrations/**`
   - `app/Models/User.php`
   - `bin/deploy.sh`

   If a CRITICAL/HIGH finding lands here, flag in `carry_forward` with severity preserved.
7. **Otherwise**: CRITICAL/HIGH → apply; MEDIUM/LOW → carry-forward.

After the fix pass, scan `.hermes/suppressions.yaml` entries — any entry whose `location:` no longer matches a real codepath is a `stale_suppression` — record in `carry_forward` so the operator can prune.

### Document

Write `$SESSION/02-analysis.md`:

```markdown
# Phase 2 — Agent Error Analysis

## Coordination with Phase 1
<one paragraph: how the 5 agents extended the static scan, what new dimensions they covered>

## Synthesized findings

### Critical (confirmed)
<bulleted: agent-name, check, file:line>

### High (confirmed)
<bulleted>

### Medium / Low (carry-forward)
<bulleted>

## Actions taken
<file:line — what changed — why — which agent flagged it>

## Carry forward
<DO-NOT-TOUCH-blocked items + MEDIUM/LOW findings>
```

Also write `$SESSION/02-fixes.json` — structured list of every edit Phase 2 applied:

```json
{
  "ts": "<TS>",
  "fixes": [
    {"file": "routes/web.php", "line": 87, "change": "added throttle:30,1", "agent": "route-auditor", "severity": "HIGH"}
  ]
}
```

Phase 6 reads `02-fixes.json` to know what to test.

### Post-validate

Run `composer hermes-fast` again to confirm the fixes didn't break CI. If FAIL: revert the most recent edits one at a time until back to baseline. Document the revert in `02-analysis.md`.

### Update MANIFEST.md

`2 | Analysis | done | <N> agents confirmed, <K> fixes applied, hermes-fast still <PASS|WARN>`

---

## Phase 3 — Update Code Docs (LLM, single)

**Goal**: Bring `docs/phase-*.md`, `docs/architecture/*.md`, and `docs/public-surface.md` in sync with the code changes Phase 2 made (and any drift the doc-syncer agent in Phase 2 already flagged but didn't fix).

### Pre-validate

Read `$SESSION/02-fixes.json` and the `Doc Syncer` section of `$SESSION/02-analysis.md`. If both empty, no doc work is required — skip to phase update.

### Execute (you do this directly — no subagents)

For each Phase 2 fix:
- If it changed a route → check `docs/public-surface.md` lists it correctly
- If it changed a controller / service → check the relevant `docs/phase-*.md` reference is still accurate
- If it changed an Inertia page or component → check architecture diagrams don't reference removed names

For each `STALE` / `UNDOCUMENTED` from Phase 2's doc-syncer:
- STALE → Edit the doc to fix the reference, or insert `> **Note (auto-synced YYYY-MM-DD):** this section is stale` callout
- UNDOCUMENTED → list as carry-forward (don't create new docs unprompted)

### Extended scope — config + top-level docs

- **`.env.example`** — if Phase 2 walk-through reported `env-missing-keys`, evaluate each key:
  - If the key in `.env.example` corresponds to a removed/refactored config and isn't read anywhere in `app/`, `config/`, or `routes/` (grep first) → **remove the line from `.env.example`**.
  - If the key IS still referenced in code → leave `.env.example` alone; the gap is that the local `.env` is stale (operator concern, not a doc bug).
  - Either way, document the decision per key in this phase's note.
- **Root `README.md`** — if Phase 2 added a new top-level capability or removed a documented one, soft-edit (single sentence update). Don't restructure. If you'd need to rewrite a whole section, report it as carry-forward instead.

### DO-NOT-TOUCH for this phase

- `docs/audit/` (entire directory)
- `docs/phase-1-foundation.md` (immutable history)
- `.env` (operator file; only `.env.example` is editable here)
- Anything else outside `docs/` and `.env.example` and `README.md`

### Document

Write `$SESSION/03-docs.md`:

```markdown
# Phase 3 — Code Docs

## Coordination with Phase 2
<which Phase 2 fixes required doc updates and why>

## Edits applied
<file — section — what changed>

## Stale not auto-fixed
<file:section — reason held back>

## Undocumented features (carry-forward)
<feature/commit — recommended phase doc title>
```

### Post-validate

Run `composer hermes-fast` — should still be PASS/WARN. Confirm no doc Edit produced broken markdown links (visual sanity check: each `[label](path)` points to a real file).

### Update MANIFEST.md

`3 | Docs | done | <N> edits, <M> stale unresolved`

---

## Phase 4 — Update Obsidian Docs (LLM, single)

**Goal**: Vault hygiene. The repo IS the Obsidian vault; this phase improves cross-linking, frontmatter consistency, and discoverability — without rewriting content.

### Pre-validate

Read `$SESSION/03-docs.md`. Look at which files were touched.

### Execute

1. **`[[wikilinks]]`** — for any plain markdown link `[label](./other-doc.md)` in `docs/README.md` that points to a doc Phase 3 touched, add the wikilink variant `[[other-doc|label]]` if missing.
2. **Frontmatter** — for any new session note Phase 2 or Phase 3 created, confirm it has `type:`, `tags:`, `date:` fields. Fix if missing.
3. **`docs/hermes/index.md`** — prepend a line for the current lifecycle run under the appropriate `### YYYY-MM` heading:
   ```markdown
   - YYYY-MM-DD HH:MM UTC — [[LIFECYCLE-YYYY-MM-DD_HH-MM|Lifecycle]] — Phase 1-N status summary
   ```
4. **`docs/README.md`** — if any new top-level doc was created in Phase 3, add it under the appropriate `### Index by topic` section.
5. **Orphans** — list any `docs/**/*.md` not referenced from `docs/README.md` or `docs/hermes/index.md`. Don't auto-link them; report.

### DO-NOT-TOUCH

Same as Phase 3 + don't change content (frontmatter and links only).

### Document

Write `$SESSION/04-obsidian.md`:

```markdown
# Phase 4 — Obsidian Vault Hygiene

## Coordination with Phase 3
<which docs Phase 3 touched and what vault-level work followed>

## Wikilinks added
<file:line — link added>

## Frontmatter fixed
<file — fields added>

## Index updates
<index.md / README.md changes>

## Orphan docs
<files not yet linked from any index>
```

### Post-validate

`grep -rE "\[\[[^]]+\]\]" docs/ | wc -l` — confirm wikilink count increased vs before (read MANIFEST baseline). Confirm `docs/hermes/index.md` has a new entry for this lifecycle session.

### Update MANIFEST.md

`4 | Obsidian | done | <N> wikilinks, <M> frontmatter fixes, <K> orphans flagged`

---

## Phase 5 — Update Flowcharts (LLM, single)

**Goal**: Regenerate Mermaid diagrams in `docs/architecture/*.md` to match current routes/services.

### Pre-validate

Read all three architecture files:
- `docs/architecture/integration-map.md`
- `docs/architecture/landing-sse-pipeline.md`
- `docs/architecture/public-stats-flow.md`

Identify which contain Mermaid blocks (```mermaid ... ```).

### Execute

For each Mermaid block:
1. Identify what it depicts (request flow / data flow / state machine / sequence)
2. Verify against current code:
   - For request flow: routes referenced still exist, controllers/middleware still present, terminal services still match
   - For data flow: tables/models/queues referenced still exist
   - For sequence: actors and endpoints still match
3. If something drifted, regenerate the diagram. Preserve the surrounding prose unless it explicitly contradicts the new diagram.

### DO-NOT-TOUCH

- Prose around the diagrams (unless it contradicts the new diagram)
- Diagrams not affected by Phase 2 changes (don't re-flow stable ones)
- Phase docs (`docs/phase-*.md`) even if they have Mermaid — they're history

### Document

Write `$SESSION/05-flowcharts.md`:

```markdown
# Phase 5 — Flowchart Updates

## Coordination with Phases 2-3
<which code/doc changes triggered which diagram updates>

## Diagrams updated
<file:section — what changed in the diagram + why>

## Diagrams verified unchanged
<file:section — confirmed still accurate>

## Mermaid render check
<grep for syntax errors — verify each updated block starts with valid Mermaid graph/sequence/flowchart syntax>
```

### Post-validate

For every Mermaid block touched, confirm it starts with a valid Mermaid declaration (`graph LR`, `graph TD`, `flowchart`, `sequenceDiagram`, etc.). Run `composer hermes-fast` — should still pass.

### Update MANIFEST.md

`5 | Flowcharts | done | <N> diagrams updated, <M> verified clean`

---

## Phase 6 — Add Tests (LLM, single)

**Goal**: For each CRITICAL/HIGH fix from Phase 2 lacking explicit test coverage, add one feature/unit test that locks in the fix.

### Pre-validate

Read `$SESSION/02-fixes.json`. For each fix:
- Check if there's already a test that would have caught the regression. Use `grep -rl <controller-or-method-name> tests/` to find existing test files.
- If found, skip — no new test needed.
- If not found, queue for test authoring.

### Coverage analysis — broader than Phase 2 fixes

Phase 2 fixes are only one source of code change. Cover the rest:

1. **Find the comparison baseline** — the most recent prior lifecycle commit, or `PRE_SESSION_HEAD~20` if this is the first lifecycle ever:
   ```bash
   prior_commit=$(git log --format=%H --grep="^hermes-lifecycle:" -1 2>/dev/null || git rev-parse HEAD~20 2>/dev/null)
   echo "$prior_commit" > $SESSION/06-coverage-baseline
   ```

2. **List all changed application files since baseline** (excludes Phase 2 fixes — they're already queued separately):
   ```bash
   git diff --name-only "$prior_commit"..HEAD -- app/ routes/ database/factories/ 2>/dev/null > $SESSION/06-changed-files.txt
   ```

3. **For each changed file**, extract testable surface:
   - Controllers (`app/Http/Controllers/**.php`) → public methods (`public function <name>`)
   - Services (`app/Services/**.php`) → public methods
   - Models (`app/Models/**.php`) → public scopes (`public function scope*`), accessors, mutators
   - Routes (`routes/**.php`) → each defined route endpoint
   - Form requests (`app/Http/Requests/**.php`) → the `rules()` method
   - Jobs / Listeners / Notifications → the `handle()` method

   Use grep to extract:
   ```bash
   for f in $(cat $SESSION/06-changed-files.txt); do
     grep -nE "public function [a-z]" "$f" 2>/dev/null
   done
   ```

4. **Check coverage for each extracted symbol**:
   ```bash
   grep -rln "<MethodOrClassName>" tests/ 2>/dev/null
   ```
   If no test file references the symbol → coverage gap. Queue for authoring.

5. **Cap the authoring queue at 5 new tests per lifecycle run**. If there are more uncovered surfaces than 5, write the top-5 highest-value tests (prioritize: public controller actions > services > form requests > others) and add the rest to `carry_forward`. Authoring 50 tests in one run risks noisy, low-quality output; the loop will pick up more next iteration.

### Pre-flight — existing test + factory drift sweep

Before authoring NEW tests, audit EXISTING ones for drift from Phase 2:

1. **Renamed symbols** — for every Phase 2 fix that renamed a class/method/route name (extract names from `02-fixes.json` change descriptions), `grep -rln <oldname>` across `tests/` and `database/factories/`. Any hit is a drift — flag the file for repair.
2. **Removed assertions** — if Phase 2 removed a code path (e.g., dropped a route), any test that asserts against it will fail on the next run. Detect proactively: `grep -rl <removed-route-pattern>` in `tests/`.
3. **Factory drift** — if Phase 2 changed `$fillable` on a model (via the migration-watcher findings — even though we can't touch migrations, `$fillable` lives on the model class which IS editable), check the matching `database/factories/<Model>Factory.php` declares all fillable fields.

For each drift found:
- **Minor drift** (renamed reference): fix the test/factory in place
- **Structural drift** (asserts gone): document in this phase's note as STALE_TEST; do NOT delete the test (might be intentional belt-and-braces)
- **No drift**: note it as VERIFIED in the phase report

### Execute

For each queued fix:

1. Determine test type:
   - **Route fix** (e.g., added throttle) → `tests/Feature/Routes/<Topic>Test.php` — assert the endpoint responds 429 on N+1 hits
   - **Auth fix** → assert 401/403 without auth
   - **Validation fix** → assert 422 with structured errors
   - **Migration fix** → `tests/Feature/Database/<Topic>Test.php`
   - **Inertia page fix** → not testable in PHP layer; skip with note
2. Write the test using Pest or PHPUnit (match existing convention in `tests/`).
3. Run `php artisan test --filter=<TestClassName>` and confirm it passes (the fix is in place, the test should be green).
4. If the new test FAILS:
   - DO NOT commit the broken test
   - Investigate: is the fix incomplete? Is the test wrong?
   - Document in this phase's note — do not silently proceed

### DO-NOT-TOUCH

- Existing tests (don't refactor — only add)
- `tests/CreatesApplication.php` and other infrastructure
- `phpunit.xml`

### Document

Write `$SESSION/06-tests.md`:

```markdown
# Phase 6 — Test Coverage

## Coordination with Phase 2 + change scan
<map each item to "test added" / "covered already" / "not testable in PHP layer" / "carried forward (cap reached)">

## Drift repair (existing tests + factories)
<file — what was renamed/repaired>

## Coverage scan
- Comparison baseline: <prior commit hash short>
- Changed files since baseline: <N>
- Testable symbols extracted: <M>
- Already covered: <K>
- Coverage gaps queued: <Q>
- Cap applied: <Q-5 carried forward to next lifecycle>

## Tests added this run
<file — test name — symbol it covers — initial run PASS/FAIL>

## Already covered (skipped)
<symbol — existing test file>

## Not testable in PHP layer
<symbol — reason (e.g. pure Vue component, frontend-only logic)>

## Carry-forward (cap reached or skipped)
<symbol — reason for deferral — priority hint for next lifecycle>

## Failures during authoring
<test that wouldn't pass + investigation; not committed>

## Test inventory delta
- Test files: <Phase 1 count> → <Phase 6 count> (Δ <delta>)
- Test methods: <Phase 1 count> → <Phase 6 count> (Δ <delta>)
```

### Post-validate

Run `composer hermes-fast` (includes the full test suite). Must still be PASS or WARN. If FAIL: a new test broke things — revert just the new tests, document, do not proceed.

### Update MANIFEST.md

`6 | Tests | done | <N> added, <M> covered, <K> skipped`

---

## Phase 7 — Validate (mixed)

**Goal**: Final pass. Confirm the lifecycle's net effect is positive — no regressions, intended fixes locked in, baseline shifted in the right direction.

### Pre-validate

Read `$SESSION/MANIFEST.md`. Confirm Phases 1-6 are all marked done. If any are pending/failed, ABORT and report.

### Execute — full re-run of static layer

In parallel via Bash:
- `composer hermes` (full, includes vite + pnpm audit)
- `composer hermes-audit`
- `composer hermes-update`
- `composer hermes-system`

### Lock-file re-verification

Re-run the same checks Phase 1 ran. If any phase incidentally edited `composer.json` or `package.json` (unlikely but possible — Phase 3 can touch the latter, Phase 2 should not touch either), the lockfile must still match:

```bash
composer validate --strict --no-check-publish
pnpm install --frozen-lockfile --lockfile-only 2>&1 | head -20
```

Compare against `$SESSION/01-lockfiles.json`. If Phase 1 was OK and Phase 7 is NOT OK → some phase silently introduced drift. Flag as CRITICAL in this phase's note (a regression Phase 8 must catch via its allow-list, but we surface it explicitly here).

### PHPStan baseline regeneration

If `phpstan` was `PASS` (no new errors) AND any baseline error was incidentally fixed by Phase 2 work:
- Re-generate the baseline: `vendor/bin/phpstan analyse --generate-baseline --memory-limit=2G`
- `wc -l phpstan-baseline.neon` before vs after — if the new file is SHORTER, that means Phase 2 silently fixed some baseline errors. Good signal.
- Document the line-count delta in this phase's note.
- Do NOT auto-regenerate if `phpstan` reported new errors — the baseline regen would absorb them and hide the regression.

### Compare against Phase 1 baseline

For each collector:
- Did `overall` improve, stay same, or regress?
- Are the Phase 1 findings that Phase 2 fixed now absent from the new findings? (They should be.)
- Are there NEW findings that didn't exist at Phase 1? (They would indicate regressions from Phase 2-6 work.)

### Git diff sanity

Run `git diff --stat HEAD` to see total file count + line count changed across all phases. Anomalies to flag:
- > 30 files changed
- > 500 lines changed
- Any file touched outside `app/`, `routes/`, `resources/`, `docs/`, `tests/`, `phpstan-baseline.neon`, `data/agents/lifecycle/<TS>/`

### Document

Write `$SESSION/07-validate.md`:

```markdown
# Phase 7 — Validate

## Coordination with all prior phases
<summary: net effect of the lifecycle in 2-3 sentences>

## Baseline vs final

| Collector | Phase 1 overall | Phase 7 overall | Findings Δ | Delta |
|---|---|---|---|---|
| CI | <P1> | <P7> | <P1 fail/warn count → P7 fail/warn count> | <improved/same/regressed> |
| Audit | <P1> | <P7> | <P1 critical+high+medium → P7 critical+high+medium> | … |
| Update | <P1> | <P7> | <P1 total outdated → P7 total outdated> | … |
| System | <P1> | <P7> | <P1 fail+warn → P7 fail+warn> | … |
| PHPStan baseline | <P1 line count> | <P7 line count> | <delta> | shrunk = good |

## Phase 2 fixes confirmed locked in
<each: fix description + collector check that proves it stuck>

## New findings (regressions)
<any findings present at Phase 7 that weren't at Phase 1>

## Git diff scope
<file count, line count, any anomalies flagged>

## Net verdict
- ✅ Lifecycle succeeded — net improvement, no regressions
- ⚠️ Lifecycle succeeded with caveats — see new findings / regressions
- ❌ Lifecycle FAILED — regressions outweigh fixes, see ROLLBACK_PLAN.md
```

### Rollback plan (emit only when verdict is ❌ FAILED)

If — and only if — Phase 7's verdict is ❌ FAILED, also write `$SESSION/ROLLBACK_PLAN.md` with the exact recovery commands:

```markdown
# Rollback Plan — Lifecycle <TS>

Lifecycle verdict was ❌ FAILED. Phase 8 will refuse to commit. To return the working tree to its pre-lifecycle state:

## Step 1 — Revert all working-tree changes from this lifecycle

```bash
git reset --hard $(cat data/agents/<TS>/PRE_SESSION_HEAD)
```

This rewinds the index + working tree to commit `<short hash>` — the HEAD at lifecycle start.

## Step 2 — Remove the lifecycle session dir

```bash
rm -rf data/agents/lifecycle/<TS>/
```

(Optional — gitignored, only matters if you want disk space back.)

## Step 3 — Review what went wrong

Read `data/agents/lifecycle/<TS>/MANIFEST.md` to see which phase failed and why. Specifically:

- Phase X's `0X-<phase>.md` for the failure details
- `07-validate.md` for the regression delta vs Phase 1

## Step 4 — Decide

- **Try again with adjustments**: e.g. tighten the DO-NOT-TOUCH list, narrow Phase 2's scope, etc.
- **Run only the safe phases manually**: e.g. just `/hermes-fleet` for the agent analysis without the docs/obsidian/flowcharts/tests/commit chain
- **Investigate the underlying regression**: if Phase 2 introduced a real bug, that's the actual problem, not the lifecycle. Fix it in a manual commit.
```

Write this AS-IS with the actual `<TS>`, hash, and phase numbers substituted. Operator should be able to copy-paste the bash blocks without modification.

### Update MANIFEST.md

`7 | Validate | done | <verdict>`

Append a "Completed: <UTC datetime>" line at the bottom.

---

---

## Phase 8 — Commit (conditional)

**Goal**: If this lifecycle session made changes worth keeping, commit them with a structured message. Never push.

### Pre-validate

1. **Was the working tree clean BEFORE this session?** Read `$SESSION/01-scan.json` — if it has a `pre_session_uncommitted` field, use it. If not, you must abort this phase with a "skipped — could not establish baseline" entry. (The setup step at the top of this file should record `git status --porcelain | wc -l` into `$SESSION/SESSION_ID`'s sibling `$SESSION/PRE_SESSION_DIRTY` before Phase 1. If you forgot, skip Phase 8 — better to lose an auto-commit than commit on top of someone's in-flight work.)

   ```bash
   pre_session_dirty=$(cat $SESSION/PRE_SESSION_DIRTY 2>/dev/null || echo "unknown")
   if [[ "$pre_session_dirty" != "0" ]]; then
     echo "Phase 8 skipped — working tree was dirty before lifecycle started"
     # write to MANIFEST and SKIP this phase
   fi
   ```

2. **Did this lifecycle actually change anything?**
   ```bash
   changed=$(git status --porcelain | wc -l)
   if [[ "$changed" == "0" ]]; then
     # no changes — write "nothing to commit" to MANIFEST and SKIP
   fi
   ```

3. **HARD REFUSAL list** — if any of these are dirty, ABORT the commit (don't auto-commit them; the lifecycle shouldn't have touched them):
   - `.env*`
   - `config/database.php`, `config/services.php`
   - `database/migrations/**`
   - `app/Models/User.php`
   - `bin/deploy.sh`

   If any are dirty: write a CRITICAL entry to MANIFEST, refuse to commit, surface the violation prominently in the final report. The lifecycle bypassed its DO-NOT-TOUCH list and that needs human investigation.

### Execute

Stage only files within the expected scope:

```bash
git add \
  app/ \
  routes/ \
  resources/ \
  tests/ \
  docs/ \
  database/factories/ \
  phpstan-baseline.neon \
  .env.example \
  README.md \
  composer.json composer.lock \
  package.json pnpm-lock.yaml 2>/dev/null || true
```

Then check what's staged:
```bash
git diff --cached --stat
```

If anything is staged outside the allow-list above (e.g., a stray file in `vendor/`, `node_modules/`, `storage/`), unstage it:
```bash
git reset HEAD <file>
```

### Commit

Build the commit message from MANIFEST data. Format:

```
hermes-lifecycle: <verdict> (<TS>)

Phase 2: <N> fixes applied across <M> files
Phase 3: <K> doc edits
Phase 4: <P> wikilinks / frontmatter fixes
Phase 5: <Q> diagrams updated
Phase 6: <R> tests added / <S> drift repairs
Phase 7: baseline <delta> | CI <P1>→<P7> | audit <P1>→<P7> | system <P1>→<P7>

Session: data/agents/lifecycle/<TS>/
Note: docs/hermes/LIFECYCLE-<TS>.md
```

Use HEREDOC to commit:

```bash
git commit -m "$(cat <<'EOF'
hermes-lifecycle: <verdict> (<TS>)

Phase 2: <N> fixes applied across <M> files
Phase 3: <K> doc edits
...

Session: data/agents/lifecycle/<TS>/
Note: docs/hermes/LIFECYCLE-<TS>.md
EOF
)"
```

NO `Co-Authored-By` line. NO emoji. Plain text only.

### Post-validate

```bash
git log -1 --format="%H %s"
git status --porcelain | wc -l
```

The new commit hash should appear, and `wc -l` should be `0` (clean working tree).

### Do NOT push

This phase ends after `git commit`. Pushing remains a manual decision — the operator reviews the diff (`git log -p HEAD~1..HEAD`) before pushing.

### Update MANIFEST.md

`8 | Commit | done | <commit hash short> — N files, K insertions, L deletions`

OR `8 | Commit | skipped | <reason>` if pre-validate rejected.

---

## Final permanent record

After all 8 phases complete (Phase 8 may be "skipped" — that's fine), write `docs/hermes/LIFECYCLE-<TS>.md` (the committed summary):

```markdown
---
date: <YYYY-MM-DD>
type: lifecycle
overall: <Phase 7 verdict>
phases_completed: 8
session_dir: data/agents/lifecycle/<TS>/
tags: [hermes, lifecycle]
---
# Lifecycle Run — <YYYY-MM-DD HH:MM UTC>

## Net effect
<2-3 sentences summarizing what the lifecycle accomplished>

## Phase summary

| Phase | Result |
|---|---|
| 1. Scan | <one-line from MANIFEST> |
| 2. Analysis | … |
| 3. Docs | … |
| 4. Obsidian | … |
| 5. Flowcharts | … |
| 6. Tests | … |
| 7. Validate | … |

## Key fixes applied
<top 5 most impactful Phase 2 fixes>

## Carry forward
<aggregated MEDIUM/LOW + DO-NOT-TOUCH-blocked items across all phases>

## Where to find details
- Full phase outputs: `data/agents/lifecycle/<TS>/` (gitignored — local only)
- MANIFEST: `data/agents/lifecycle/<TS>/MANIFEST.md`
- Each phase's full report: `data/agents/lifecycle/<TS>/0N-<phase>.md`

## Delta since last lifecycle run

<auto-populated by the trend comparison step below>
```

### Trend comparison (add this section to LIFECYCLE-<TS>.md)

Before writing the final summary file, gather the last 5 lifecycle runs for trend context:

```bash
ls -1t docs/hermes/LIFECYCLE-*.md 2>/dev/null | head -5
```

For each prior LIFECYCLE-*.md file (newest first, excluding the current one being written), extract these metrics from their frontmatter + body:

- `overall` (verdict)
- PHPStan baseline line count (from Phase 7 table)
- Update Inspector total outdated count
- Audit Sentinel CRITICAL + HIGH count
- System Check FAIL + WARN count
- Phase 2 fix count

Build a "Delta since last run" table comparing the current run's metrics against the most recent prior run:

```markdown
## Delta since last lifecycle run

Prior run: [[LIFECYCLE-YYYY-MM-DD_HH-MM|<prior timestamp>]] (<verdict>)

| Metric | Prior | Current | Δ | Trend |
|---|---|---|---|---|
| Verdict | <prior> | <current> | — | <better/same/worse> |
| PHPStan baseline lines | <N> | <M> | <delta> | <shrinking ↘ = good> |
| Outdated deps total | <N> | <M> | <delta> | <shrinking ↘ = good> |
| Audit CRITICAL+HIGH | <N> | <M> | <delta> | <shrinking ↘ = good> |
| System FAIL+WARN | <N> | <M> | <delta> | <stable or shrinking = good> |
| Test files | <N> | <M> | <delta> | <growing ↗ = good> |
| Test methods | <N> | <M> | <delta> | <growing ↗ = good> |
| Test failures | <N> | <M> | <delta> | <0 = required> |
| Coverage gaps carried forward | <N> | <M> | <delta> | <shrinking ↘ = good> |
| Phase 2 fixes applied | <N> | <M> | — | <both are flow values, no trend> |

5-run mini-history (oldest → newest):
- <ts1>: <verdict> · baseline <N> · outdated <M> · audit <K> · tests <T> (Δ<+/-X>)
- <ts2>: <verdict> · baseline <N> · outdated <M> · audit <K> · tests <T> (Δ<+/-X>)
- <ts3>: <verdict> · baseline <N> · outdated <M> · audit <K> · tests <T> (Δ<+/-X>)
- <ts4>: <verdict> · baseline <N> · outdated <M> · audit <K> · tests <T> (Δ<+/-X>)
- <ts5>: <verdict> · baseline <N> · outdated <M> · audit <K> · tests <T> (Δ<+/-X>)

Net trend: <one sentence — "PHPStan debt is steadily declining, dep updates are accumulating", etc.>
```

If this is the FIRST lifecycle run (no prior LIFECYCLE-*.md exists), substitute the trend section with:

```markdown
## Delta since last lifecycle run

_First lifecycle run — no prior baseline to compare against. Future runs will show trends here._
```

## Update docs/hermes/index.md

Phase 4 already added the lifecycle entry. Confirm it's there; if missing, add it.

## Embed lifecycle visualization

At the bottom of `docs/hermes/LIFECYCLE-<TS>.md`, append a Mermaid phase-flow diagram with status colors. Use the actual phase outcomes from MANIFEST:

```markdown
## Phase flow visualization

\`\`\`mermaid
graph LR
    Setup([Setup])
    Setup --> P1[1: Scan]
    P1 --> P2[2: Analysis]
    P2 --> P3[3: Docs]
    P3 --> P4[4: Obsidian]
    P4 --> P5[5: Flowcharts]
    P5 --> P6[6: Tests]
    P6 --> P7[7: Validate]
    P7 --> P8[8: Commit]

    classDef pass fill:#d4f4dd,stroke:#34a853
    classDef warn fill:#fef3c7,stroke:#f59e0b
    classDef fail fill:#fee2e2,stroke:#ef4444
    classDef skip fill:#e5e7eb,stroke:#6b7280

    class P1 <pass|warn|fail>
    class P2 <pass|warn|fail>
    class P3 <pass|warn|fail>
    class P4 <pass|warn|fail>
    class P5 <pass|warn|fail>
    class P6 <pass|warn|fail>
    class P7 <pass|warn|fail>
    class P8 <pass|warn|fail|skip>
\`\`\`
```

Substitute the actual `pass`/`warn`/`fail`/`skip` per phase based on MANIFEST. Phase 8 uses `skip` if pre-validate rejected.

## Regenerate the cross-session dashboard

After writing the lifecycle note + visualization, run:

```bash
composer hermes-dashboard
```

This regenerates `docs/hermes/dashboard.md` to include this new lifecycle run. The dashboard is the single-pane-of-glass view across all session types (LIFECYCLE / FLEET / DOCS / AUDIT / UPDATE / SYSTEM) with Mermaid timelines and verdict tallies.

## Final report (your turn output)

End with TWO LINES:

```
lifecycle <verdict>, <K> total fixes, <N> tests added, <M> diagrams updated, <P> doc edits
session: data/agents/lifecycle/<TS>/  |  permanent: docs/hermes/LIFECYCLE-<TS>.md
```

---

## Notes on cost and safety

- **NEVER shell out to `claude --print` or `claude -p` from any phase, sub-step, or helper.** Every LLM call MUST be via the Agent tool inside the current interactive session — that's the carve-out that makes this lifecycle free under Anthropic's post-2026-06-15 split. A single `claude --print` invocation anywhere in the chain breaks the cost model.
- Every step runs inside the current interactive Claude Code session — subscription billing, no credit-pool burn.
- Single fan-out (5 subagents) happens in Phase 2; all other phases are single-agent main-session work.
- Total wall-clock: typically 30-60 minutes depending on Phase 2 fix count and Phase 6 test surface.
- If at any point a phase post-validate fails: STOP the lifecycle, write what you have to MANIFEST.md, surface the failure to the user. Do not proceed to the next phase on broken state.
- The session dir is in `data/` (gitignored); the permanent record in `docs/hermes/LIFECYCLE-*.md` (committed). Always update both.
