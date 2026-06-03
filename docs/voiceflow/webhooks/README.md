# Voiceflow Webhooks (mirror)

Local mirror of the two **incoming-events** webhook surfaces that Voiceflow can push to your service.

Both surfaces are configured in the Voiceflow dashboard — there is no REST API for managing webhook subscriptions in the documented public surface.

## Files

| File                     | Direction               | Scope                | Configured under                | Signed?              |
| ------------------------ | ----------------------- | -------------------- | ------------------------------- | -------------------- |
| `session-lifecycle.md`   | Voiceflow → your URL    | Per-project          | Settings → Webhooks (project)   | Not documented       |
| `org-events.md`          | Voiceflow → your URL    | Per-organization     | Organization Settings           | Yes — Svix HMAC signature with rotatable secret |

## Event surface at a glance

### Session lifecycle (per project)

- `runtime.call.start`, `runtime.call.end` — Twilio and web-voice call boundaries (with platform-specific metadata: `callSid`, `callType`, `userNumber`, `agentNumber` for Twilio).
- `runtime.session.start`, `runtime.session.end` — chat/voice session boundaries.
- Phone calls fire **both** `call.*` and `session.*` events.

### Organization events (per org)

- `organization.project.created`
- `organization.project.deleted`
- `organization.project.environment.created`
- `organization.project.environment.published`
- `organization.project.environment.merged`
- `organization.project.environment.deleted`

All events share an envelope: `{ type, data, time (unix ms), resource }`. The `resource` is `project-{projectID}` for session-lifecycle events and `organization-{organizationID}` for org events.

## Security notes

- **Org events** are signed via Svix (HMAC). A webhook secret is generated on registration; the prior secret remains valid for **24 hours** after regeneration to support rotation. Source IPs are Svix's, not Voiceflow's.
- **Session-lifecycle events** have no documented signing scheme — treat the destination URL itself as the trust boundary (use an unguessable path) until Voiceflow confirms otherwise.
- Retry policies are not documented for either surface. (Svix's defaults presumably apply to org events.)

## Pages

Both pages mirrored successfully — no failures.
