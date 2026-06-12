# Contributing

Working conventions for this repo. The full assurance spec lives in
[PROJECT_ASSURANCE_STRATEGY.md](PROJECT_ASSURANCE_STRATEGY.md); this file records
the decisions and the day-to-day rules.

## Tooling decisions

- **PHPUnit, not Pest.** The existing suite is class-based PHPUnit; new tests follow
  suit. Spec examples written in Pest syntax are translated to PHPUnit.
- **Style:** Pint, `laravel` preset — `vendor/bin/pint --test` must pass.
- **Static analysis:** PHPStan level 6 + Larastan + shipmonk dead-code detector over
  `app/`, `routes/`, `database/`. The baseline (`phpstan-baseline.neon`) is
  **shrink-only**: never add to it, only remove.
- **Local watchdog:** run `composer hermes-fast` before pushing — it mirrors CI
  (pint, phpstan, tests, config/route/migration checks, composer audit).

## [IF-*] applicability decisions (spec §I7 / §I5.8)

| Marker | Decision |
|---|---|
| **[IF-UI]** | Yes — Inertia/Vue dashboard. Browser/E2E (Dusk/Playwright) **deferred** until staging exists; coverage today is Inertia feature tests (`assertInertia`). |
| **[IF-QUEUE]** | Queue is `database` in production, `sync` in tests. Queue SLA tests (G6: tries/backoff/timeout pins, dead-letter fallback) **deferred**. |
| **[IF-I18N]** | No — single-locale app. No translation-completeness test. |
| **[IF-API-CONSUMERS]** | None external yet — the API surface is the dashboard itself plus the embed widget. OpenAPI contract + breaking-change diff gate (H1) **deferred** until a third-party consumer exists. |

## Workflow conventions (adapted from spec §I5)

1. **Bug fix:** write the failing regression test first; test name references the
   issue; commit test + fix together.
2. **New feature:** unit tests for domain logic, at least one feature test per
   endpoint, a budget test if the endpoint is SLA-relevant, a policy test if it adds
   an ability, wiring pins if it adds bindings/events/named routes.
3. **Snapshots:** regenerated only via `REGENERATE_SNAPSHOTS=1`; the resulting diff
   is reviewed like code, never rubber-stamped.
4. **PHPStan baseline:** shrink-only (see above).
5. **SLA changes:** any edit to `config/sla.php` updates the budget tests
   (`tests/Performance/BudgetTest.php`) in the same PR — numbers and tests move
   together. (k6 thresholds join this rule once the load suite exists.)
6. **Pre-push:** `composer hermes-fast` green before pushing.

## SLA / performance

CI budgets live in `config/sla.php` and are enforced by
`tests/Performance/BudgetTest.php`. They are deliberately generous (CI runners are
noisy) and exist to catch order-of-magnitude regressions. All targets are
**placeholders to be confirmed by the team before production**. LLM-turn latency is
dominated by the provider (seconds) — chat budgets measure our pipeline with the
provider faked.

`/api/health` (unauthenticated, throttled) is the uptime-monitoring probe: DB +
cache checks only, never LLM/provider calls.

## Deferred until staging exists

Tracked as phases 12–16 in PROJECT_ASSURANCE_STRATEGY.md (§I6):

- k6 load/stress/soak suite + release gate (G4, I2 `load` job)
- Mutation testing job (A4, I2 `mutation` job)
- Post-deploy smoke against production (H3)
- Rollback & backup-restore drills (H4)
- Queue SLA layer (G6) and the other deferred [IF-*] layers above

Do not add placeholder CI jobs for these — the pipeline only contains steps that
actually run.
