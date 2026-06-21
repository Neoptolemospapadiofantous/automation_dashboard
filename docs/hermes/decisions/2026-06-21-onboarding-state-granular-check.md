---
date: 2026-06-21
type: decision
status: active
tags: [hermes, decisions, learning, manifest]
---

# app/Lifecycle declares an `onboarding-state` granular check

## Context

The learning loop (`composer hermes-learn`) flagged `app/Lifecycle` `[tenancy/high]`
as a node with an **escaped defect but no granular check**. The escape was commit
`6fce398` (`fix(onboarding): active agents are Complete regardless of mode + env
config`) — a real user bug where a managed agent with `status=active` but drifted
`VOICEFLOW_MASTER_PROJECT_ID` env config resolved to `NeedsCredentials` and
redirect-looped the user through the BYOK paste-keys form.

The fix author already shipped two regression guards in
`tests/Feature/OnboardingStateTest.php`, and that file was already listed under the
node's broad `tests`. So the gap the heuristic detected was **localization, not
coverage**: a regression there only tripped the repo-wide `tests` gate, never
pointed at `app/Lifecycle`.

## Decision

Add an `onboarding-state` granular checkdef to `docs/hermes/manifest.json`
targeting the existing `tests/Feature/OnboardingStateTest.php`, and opt
`app/Lifecycle` into it. No new test was written.

## Rationale

- The bug class (status-vs-env-config divergence in onboarding resolution) is a
  genuine `high`-criticality invariant worth a node-localized guard.
- The regression tests already exist; writing new ones would duplicate coverage.
- A granular check makes a future regression localize to `app/Lifecycle` (with its
  blast radius — `app/Events/Domain`, `app/Models`) instead of only failing the
  broad `tests` gate.

## Alternatives rejected

| Option | Why no |
|---|---|
| Write a new regression test | Commit `6fce398` already added two in `OnboardingStateTest`; would duplicate |
| Leave `app/Lifecycle` with only broad checks | A `high`-criticality node that took a real escaped bug should localize failures |
| Bump criticality instead | Already `high`; the gap was localization, not weight |

## Consequences

- `composer hermes-learn` no longer flags `app/Lifecycle` (escaped-bug gap list
  dropped from 2 nodes to 1).
- `composer hermes-tree --node app/Lifecycle` now runs `onboarding-state` as a
  first-class, node-attached finding.
- Indexed here so lifecycle Phase 2 / the fleet won't auto-revert the manifest edit
  as a "redundant check".

## Related

- `6fce398` — the escaped fix this guard locks in
- [[2026-06-14-learning-is-periodic-not-automated]] — the loop that surfaced this
- `docs/hermes/manifest.json` — the trunk edited
