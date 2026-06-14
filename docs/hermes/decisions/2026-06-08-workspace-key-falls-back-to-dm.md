---
date: 2026-06-08
type: decision
status: active
tags: [hermes, decisions, voiceflow, credentials]
---

# Voiceflow workspace key falls back to DM key

## Context

Voiceflow exposes two distinct API key types:

- **DM key** (`VF.DM.*`) — Dialog Manager, used for `runtime/v4/session` and `state/user/{id}`
- **Workspace key** — required for `analytics-api` (transcripts, evaluations, usage) and `realtime-api` (KB CRUD, environments)

Both are per-agent fields on the `agents` table; both have config-level fallbacks for CLI/test contexts.

## Decision

`VoiceflowService::workspaceKey()` and `VoiceflowServiceProvider::workspaceKeyFor()` return `workspaceApiKey ?: apiKey` — falling back to the DM key when no workspace key is configured.

## Rationale

- **Historical**: some Voiceflow accounts let the DM key talk to workspace surfaces too. Pre-Phase 13 tenants were onboarded with only a DM key configured
- **Soft failure**: a 401 from the workspace surface is a recoverable error (operator sees "configure workspace key"); a hard refusal at config-resolution time would break existing tenants until they update their settings
- **Tests cover it**: `VoiceflowWorkspaceKeyTest` asserts both paths — workspace key when present, DM-key fallback when not, AND DM-only surfaces never accidentally use the workspace key

## Alternatives rejected

| Option | Why no |
|---|---|
| Hard-fail if workspace key missing | Breaks existing tenants on the next release; surfaces a deploy-time error that should have been an inbox-time onboarding nudge |
| Skip workspace surfaces silently | Worse — KB queries return empty, no error, no diagnostic |
| Auto-register workspace key from DM key | Voiceflow doesn't expose a "register workspace key" endpoint; this isn't possible |

## Consequences

- Operators with only a DM key configured can still hit transcripts/KB query/usage — but those calls will 401 from Voiceflow if the account doesn't have DM→workspace mapping
- The 401 path goes through `AuthException` (Phase A foundation) — caller can `catch (AuthException)` to surface a "configure workspace key in agent settings" message
- This decision is opt-in soft via `voiceflow_workspace_api_key` field; tenants who configure both get the strict-workspace behavior automatically

## Related

- `app/Services/VoiceflowService.php` — `workspaceKey()` helper
- `app/Providers/VoiceflowServiceProvider.php` — `workspaceKeyFor($agent)` static helper
- `tests/Feature/VoiceflowWorkspaceKeyTest.php` — both-paths coverage
