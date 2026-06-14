---
date: 2026-06-08
type: decision
status: active
tags: [hermes, decisions, voiceflow, webhooks, security]
---

# No `svix/svix` composer dep — implement Svix HMAC verification in-house

## Context

The Voiceflow `organization.project.*` webhook stream is signed using the Svix Standard Webhooks specification: three headers (`svix-id`, `svix-timestamp`, `svix-signature`), HMAC-SHA256 over `{id}.{ts}.{body}`, base64-encoded secret with a `whsec_` prefix, multi-signature header support for key rotation.

The official `svix/svix` composer package handles this verification (plus a webhook-subscription management API and a poller consumer we don't need).

## Decision

Implement verification in `app/Services/Voiceflow/Webhooks/SvixVerifier.php` — ~80 lines, zero transitive deps, matches the published spec exactly. Wired into `OrgEventsController` with shared-secret fallback for tenants without Svix configured.

## Rationale

- **Surface area** — we use ~5% of the SDK (verify-signature) and would carry ~95% as transitive weight
- **Spec is fixed** — Standard Webhooks v1 hasn't shipped a breaking change since 2022; the format risk is low
- **Test coverage** — 8 focused tests cover the verifier exhaustively (`VoiceflowSvixVerificationTest`), more than the SDK's own tests for the parts we use
- **Cost model** — every composer dep is a future supply-chain audit; the saved bytes/audits/version bumps outweigh the saved write-time
- **Failure mode is loud** — a spec change breaks our verifier with a clear "signatures don't match"; SDK would also fail, just less legibly

## Alternatives rejected

| Option | Why no |
|---|---|
| `composer require svix/svix` | Pulls ~10 transitive packages we don't use; ongoing audit burden |
| Skip signature verification entirely | Webhook surface is a real attack vector; org-events can retire pool entries |
| Verify only the shared platform secret | Voiceflow's documented mechanism IS Svix; we should honor it when configured |

## Consequences

- If Svix changes the signing format (low probability), `VoiceflowSvixVerificationTest` will fail on the next test run with an obvious "signatures don't match" message
- Adding new Svix-signed webhook surfaces in the future is `new SvixVerifier()`, not "require a new SDK"
- Operators configure `VOICEFLOW_SVIX_SECRET` in `.env` to enable; fallback to `VOICEFLOW_ORG_WEBHOOK_SECRET` keeps things working without Svix at all

## Related

- `app/Services/Voiceflow/Webhooks/SvixVerifier.php`
- `app/Http/Controllers/Voiceflow/OrgEventsController.php`
- `tests/Feature/VoiceflowSvixVerificationTest.php`
