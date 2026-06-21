# Pre-Production Gate — Agent Implementation Spec

A task list for an AI coding agent to implement a pre-production validation gate.

## How the agent should use this document

- Work **top-down**. Tasks are ordered by dependency. Do not start a task whose
  `Depends on` is unmet.
- Each task has a **Done when** block. That is the acceptance criterion — the task is not
  complete until every bullet is objectively true and verifiable.
- **Ship every new check as a warning first** (non-blocking), then flip to blocking only
  after `GATE-OPS-02` (metrics) shows it has a low false-positive rate. Do not introduce a
  new check directly as a hard block.
- Keep checks **fast and cheap-first**: a per-PR check that exceeds the speed budget
  (`GATE-000`) must be moved to the async lane, not left in the PR path.
- This spec is stack-agnostic. `GATE-000` resolves the stack; every later task's concrete
  tooling is chosen there. Do not hard-code tools before completing `GATE-000`.
- Mark any intentional shortcut with a `// gate-debt:` comment naming the upgrade path.

**Type tags:** `per-PR` (runs on every change) · `async` (scheduled / on-demand) ·
`post-deploy` (runs after release). **Enforcement:** `block` / `warn`.

---

## Stage 0 — Discovery & architecture (build first; everything depends on these)

### GATE-000: Resolve context
**Depends on:** none
**Build:** Detect language/runtime, package manager, CI system, deploy target
(containers? serverless? VMs?), and whether a UI is shipped. Detect what gate-like checks
already exist. Record findings in `gate/context.md`.
**Done when:**
- `gate/context.md` lists stack, CI, deploy target, UI yes/no, and existing checks.
- A tool is chosen for each later task and recorded (no later task invents its own tool).

### GATE-001: Risk-tiering engine
**Depends on:** GATE-000
**Build:** A classifier that assigns each change a tier from its diff. Suggested tiers:
`trivial` (docs/comments/config-noise), `standard` (app code), `sensitive`
(auth, payments, migrations, public API, IaC, dependency changes). Define which task sets
run per tier — trivial skips heavy suites; sensitive runs the full set plus review triggers.
**Done when:**
- A change's tier is computed deterministically from its file paths + diff.
- Each later check declares which tiers it runs on.
- A docs-only change provably skips e2e/load; an auth change provably runs the full set.

### GATE-002: Gate-as-code + single status surface
**Depends on:** GATE-000
**Build:** Define the entire gate as version-controlled config in `gate/` that goes through
review like any other change. Produce one consolidated status (one check run / one summary)
that reports every sub-check's result for a change.
**Done when:**
- Editing the gate requires a reviewed PR.
- One status surface shows pass/fail/warn for every sub-check on a given change.

### GATE-003: Speed budget + fail-fast ordering
**Depends on:** GATE-002
**Build:** Order per-PR checks cheapest-first (lint → type → unit → integration → e2e).
Enforce a per-PR wall-clock target (default 10 min); anything slower is routed to the async lane.
**Done when:**
- A lint failure returns without having paid for the slow suite.
- Total per-PR time is measured and stays under budget; over-budget checks are async.

### GATE-004: Break-glass override
**Depends on:** GATE-002
**Build:** An audited bypass path requiring a written reason + an approver, which auto-files
a ticket. No silent overrides.
**Done when:**
- An override is impossible without a reason + approver.
- Every override emits an audit record and a follow-up ticket.

---

## Stage 1 — Foundation checks (all `per-PR` / `block`)

### GATE-101: Lint / format — fail on errors, autofix warnings.
### GATE-102: Type check — strict, no implicit-any / untyped escapes.
### GATE-103: Unit tests pass.
### GATE-104: Coverage ratchet — line + branch; number can only rise, never fall.
### GATE-105: Secret scanning — diff **and** history; block on any hit.
### GATE-106: SCA dependency CVE scan — block on critical/high.
### GATE-107: SAST — block on critical/high.
### GATE-108: Build + artifact — compiles/packages; artifact produced.
### GATE-109: Branch protection + required reviewers — no direct push; code-owner sign-off on sensitive paths.

**Done when (each):** the check runs on its tiers, blocks on the stated condition, surfaces
in `GATE-002`, and emits a clear fix message (see `GATE-OPS-03`).

---

## Stage 2 — Correctness & integration (`per-PR`)

### GATE-201: Integration / contract tests `block` — service boundaries + API contracts.
### GATE-202: Backward-compatibility check `block` — schema/API diff doesn't break consumers.
### GATE-203: Migration validation `block` — runs clean forward **and** back on a throwaway copy; reversible; non-destructive by default.
### GATE-204: E2E / smoke on staging `block`.
### GATE-205: Lockfile integrity `block` — lockfile matches manifest; no floating versions.
### GATE-206: License compliance `block` — disallowed licenses for your distribution model.
### GATE-207: Config validation `block` — schema-check env config; no missing required keys per environment.

---

## Stage 3 — Hardening & supply chain (`per-PR` unless noted)

### GATE-301: Complexity caps `block` — cyclomatic/cognitive threshold per function/file. (The real anti-over-engineering gate.)
### GATE-302: Debt-marker scan `warn→block` — surface `gate-debt:` / `ponytail:` / `TODO` / `FIXME` so deferred shortcuts don't ship silently.
### GATE-303: Container/image scan `block` — base-image CVEs (if shipping containers).
### GATE-304: IaC security scan `block` — Terraform/CFN/K8s misconfig.
### GATE-305: Artifact signing + provenance `block` — signed artifacts, SLSA-style attestation.
### GATE-306: SBOM generation `block` — produce + store a bill of materials.
### GATE-307: Approved-registries-only `block` — deps/images from approved sources.
### GATE-308: Static-analysis quality gate `warn→block` — duplication %, maintainability, smell thresholds.
### GATE-309: Dead-code / unused-dep detection `warn→block`.
### GATE-310: PII-in-logs scan `warn→block` — new logging/telemetry must not emit personal data.
### GATE-311: Cost-impact gate `warn` — for IaC, surface the dollar delta before merge.

---

## Stage 4 — Operational readiness

### GATE-401: Health/readiness endpoint exists + passes `block`.
### GATE-402: Resource limits set `block` — CPU/mem requests+limits; no unbounded containers.
### GATE-403: Rollback path verified `warn→block` — reversible deploy; DB changes have a tested down-path.
### GATE-404: Observability readiness `warn` — logs/metrics/traces/alerts wired for new paths.
### GATE-405: Feature-flag hygiene `warn` — risky new code flag-gated; flags owned + documented.
### GATE-406: SLO / error-budget check `warn` — block risky changes while burning budget.
### GATE-407: Deploy-window / freeze policy `block` — enforce no-deploy windows + incident freezes.
### GATE-408: Docs/ADR/runbook gate `warn` — sensitive-tier changes link an ADR; new services have a runbook.

---

## Stage 5 — Deeper assurance (`async`)

### GATE-501: DAST — dynamic testing against running staging.
### GATE-502: Performance regression `block if baselines stable, else async` — latency/throughput vs. budget, tested at realistic data volume.
### GATE-503: Load / stress — for high-risk / high-traffic changes.
### GATE-504: Mutation testing — catch assertions that don't assert.
### GATE-505: Flaky-test detection / quarantine.
### GATE-506: Infra drift detection — declared vs. actual.
### GATE-507: Environment parity + data realism — staging resembles prod in config/shape/scale; non-prod holds no unmasked prod PII.

---

## Stage 6 — Post-deploy (`post-deploy`)

### GATE-601: Post-deploy smoke verification `block` — smoke tests fire after the release lands.
### GATE-602: Auto-rollback `block` — a failed post-deploy health check rolls back automatically.

**Done when:** a deliberately broken release is caught post-deploy and reverted without a human.

---

## Stage 7 — Governance triggers (tier-driven)

### GATE-701: Change traceability `warn→block` — commit/PR links an issue or change record.
### GATE-702: Audit-log completeness `block in regulated contexts`.
### GATE-703: Data-privacy review trigger `warn` — flag changes touching PII handling.
### GATE-704: Threat-model trigger `warn` — auth/payments/new-external-surface changes flag for security review.

---

## Frontend track (only if `GATE-000` found a UI)

### GATE-F01: Bundle-size budget `block` — block on regressions (ratchet).
### GATE-F02: Accessibility audit `warn→block` — axe/Lighthouse a11y threshold.
### GATE-F03: Lighthouse performance budget `warn→block`.
### GATE-F04: Visual regression tests `warn`.

---

## Stage OPS — Operating model (build alongside, not after)

### GATE-OPS-01: Ownership — every check has a named owner accountable for its accuracy + false-positive rate. Ownerless checks are not allowed to be `block`.
### GATE-OPS-02: Measurement — track escape rate (bugs that got through), false-positive rate, gate duration trend, and override frequency. This drives every `warn→block` promotion decision.
### GATE-OPS-03: Failure docs — every failure mode emits *what broke and how to fix it*, not just a red X.
### GATE-OPS-04: Warn-then-block promotion — a check moves to `block` only when `GATE-OPS-02` shows a sustained low false-positive rate.

**Done when:** no check is `block` without an owner; the four metrics in OPS-02 are visible
on a dashboard; every failure links a fix doc.

---

## Note on ponytail

Not a gate task. It's a developer-side authoring nudge that makes leaner code arrive at the
gate. Its only gate-relevant output is the `ponytail:` shortcut comment, already covered by
`GATE-302`. Don't have the agent wire ponytail *into* the gate.

## Before the agent starts

Confirm stack + CI in `GATE-000` first. If you give me the language/runtime and CI system, I
can pre-fill the concrete tool per task so the agent isn't choosing them itself.
