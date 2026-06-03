---
title: Organization Events Webhook
method: POST (outbound from Voiceflow to your URL)
path: configured per-organization in Organization Settings
auth: HMAC signature using an org-scoped webhook secret (Svix). Old secret valid 24 h after regeneration. Events originate from Svix IPs.
summary: Receive organization-level lifecycle events for projects and environments — created, deleted, published, merged.
source: https://docs.voiceflow.com/api-reference/webhooks/org-events
---

# Organization Events Webhook

Voiceflow sends `POST` requests to your configured URL whenever a project or environment is created / deleted / published / merged anywhere in the organization.

## Registration

Configured in the Voiceflow dashboard under **Organization Settings**. Paste the destination URL in the box provided; all future events are sent to it automatically.

A webhook **secret** is generated on registration. The secret can be regenerated from the same screen — the previous secret remains valid for **24 hours** after regeneration to allow zero-downtime rotation.

## Event types

| Event                                      | Trigger                                          |
| ------------------------------------------ | ------------------------------------------------ |
| `organization.project.created`             | A new project is created.                        |
| `organization.project.deleted`             | A project is deleted.                            |
| `organization.project.environment.created`   | A new environment is added to a project.       |
| `organization.project.environment.published` | An environment is published (release cut).     |
| `organization.project.environment.merged`    | An environment is merged into another.         |
| `organization.project.environment.deleted`   | An environment is removed.                     |

## Payload envelope

All events share the same outer envelope:

```ts
{
  "type":     "string",                              // event identifier above
  "data":     object,                                // event-specific, see below
  "resource": "organization-{organizationID}",
  "time":     1717420000000                          // unix ms
}
```

## Event-specific `data` shapes

### Project Created / Deleted

```ts
{
  "createdBy":        { "type": "string", "userEmail": "string" },  // or "deletedBy" on the delete event
  "organizationID":   "string",
  "projectID":        "string",
  "workspaceID":      "string",
  "projectMetadata":  { "name": "string" }
}
```

### Environment Created

```ts
{
  "createdProjectEnvironmentID":       "string",
  "createdProjectEnvironmentMetadata": {
    "alias":             "string",
    "isLive":            true,
    "name":              "string",
    "trafficPercentage": 0
  },
  "source": {
    "environmentID":       "string",
    "environmentMetadata": { /* same alias/isLive/name/trafficPercentage shape */ },
    "type":                "string"
  }
}
```

### Environment Published

```ts
{
  "publishedProjectEnvironmentID":       "string",
  "publishedProjectEnvironmentMetadata": { "alias": "...", "isLive": true, "name": "...", "trafficPercentage": 100 },
  "publishedVersionIDBefore":            "string",
  "publishedVersionIDAfter":             "string"
}
```

### Environment Merged

```ts
{
  "sourceProjectEnvironmentID":       "string",
  "sourceProjectEnvironmentMetadata": { /* env metadata */ },
  "targetProjectEnvironmentID":       "string",
  "targetProjectEnvironmentMetadata": { /* env metadata */ },
  "sourceProjectEnvironmentRemoved":  true
}
```

### Environment Deleted

```ts
{
  "deletedProjectEnvironmentID":       "string",
  "deletedProjectEnvironmentMetadata": { "alias": "...", "isLive": true, "name": "...", "trafficPercentage": 0 }
}
```

## Security model

- Voiceflow signs each event with the org-scoped **webhook secret** using the **Svix** signature scheme. Verify the signature in your handler using the standard Svix client library or by reproducing the HMAC manually.
- Events originate from **Svix** infrastructure, so you cannot allow-list by Voiceflow's own IP ranges — allow-list Svix's documented IPs if you need network-level filtering.
- Secret rotation: regenerate via the dashboard refresh button; the prior secret remains valid for 24 hours.

## Retry behavior

Not documented on this page. Svix's default retry/backoff schedule presumably applies, but Voiceflow does not commit to specifics here.
