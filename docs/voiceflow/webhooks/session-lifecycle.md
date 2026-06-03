---
title: Session Lifecycle Webhook
method: POST (outbound from Voiceflow to your URL)
path: configured per-project in Settings → Webhooks
auth: Webhook URL configured per project (no signing scheme documented for this surface)
summary: Receive runtime events for each phone call and chat session — start and end. Phone calls fire both call.* and session.* events.
source: https://docs.voiceflow.com/api-reference/webhooks/session-lifecyle
---

# Session Lifecycle Webhook

Voiceflow sends `POST` requests to your configured URL whenever a call or session starts or ends.

## Registration

Configured in the Voiceflow dashboard under **Settings → Webhooks** (per project). Add the destination URL and Voiceflow will start dispatching events to it.

## Event types

| Event                      | Trigger                                                  |
| -------------------------- | -------------------------------------------------------- |
| `runtime.call.start`       | A Twilio or web-voice call starts.                       |
| `runtime.call.end`         | A Twilio or web-voice call ends.                         |
| `runtime.session.start`    | A user session begins (any channel).                     |
| `runtime.session.end`      | A user session ends.                                     |

Phone calls fire **both** the `runtime.call.*` events and the corresponding `runtime.session.*` events.

## Payload envelope

All four events share the same outer envelope:

```ts
{
  "type": "runtime.call.start | runtime.call.end | runtime.session.start | runtime.session.end",
  "data": { /* event-specific, see below */ },
  "time": 1717420000000,           // event timestamp (unix ms)
  "resource": "project-{projectID}"
}
```

## `runtime.call.start` / `runtime.call.end` data

```ts
{
  "userID":        "string",
  "projectID":     "string",
  "environmentID": "string",
  "startTime":     1717420000000,  // unix ms
  "endTime":       1717420060000,  // call.end only
  "endReason":     "string",       // call.end only
  "platform":      "twilio" | "web-voice",
  "metadata":      object
}
```

### Platform-specific `metadata`

Twilio:
```ts
{
  "callSid":     "string",
  "callType":    "inbound" | "outbound" | "prototype-outbound",
  "userNumber":  "string",       // caller phone number
  "agentNumber": "string"        // bot phone number
}
```

Web voice: empty object `{}`.

## `runtime.session.start` / `runtime.session.end` data

```ts
{
  "userID":        "string",
  "projectID":     "string",
  "environmentID": "string",
  "sessionID":     "string",
  "startTime":     1717420000000,
  "endTime":       1717420060000   // session.end only
}
```

## Security model

The public docs for this webhook surface do **not** describe a signing/verification scheme, a shared secret, or source IP allow-list. Treat the URL itself as the only trust boundary (use an unguessable path) until/unless Voiceflow ships HMAC signing here. The org-events webhook surface (see `org-events.md`) does use Svix-based signatures — but the session-lifecycle docs do not confirm that the same scheme applies here.

## Retry behavior

Not documented.
