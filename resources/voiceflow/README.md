# Voiceflow template (`lead-qualification.vf`)

The onboarding wizard offers users a downloadable `.vf` project to import
into Voiceflow as their starting point. The wizard links to
`/templates/lead-qualification.vf` (served from `public/templates/`).

**That file has to be built and exported manually inside the Voiceflow IDE
— it's a binary project export, not something we can hand-roll in this
repo.** The placeholder file at `public/templates/lead-qualification.vf`
is a text stub so the download link returns *something* during local dev;
production deployments should overwrite it with a real export.

## What the template must contain

Match these so the dashboard's webhook + lead-capture pipeline works
without per-user customization:

### 1. Captured variables (exact names)

The capture controller and `services.voiceflow.lead_variables` config
both read these keys from the agent's session state:

| Voiceflow variable | Maps to lead column |
|---|---|
| `name` | `leads.name` |
| `email` | `leads.email` |
| `phone` | `leads.phone` |
| `company` | `leads.company` |

The template's flow should `Set` these variables as it collects them.

### 2. Custom Action: POST to the dashboard

When the agent has captured enough to qualify a lead, fire a Custom
Action HTTP call to:

```
POST {{webhook_url}}
Headers:
  X-Webhook-Secret: {{webhook_secret}}
  Content-Type: application/json
Body (JSON):
  {
    "voiceflow_user_id": "{user_id}",
    "name": "{name}",
    "email": "{email}",
    "phone": "{phone}",
    "company": "{company}",
    "qualified": true,
    "score": {score},
    "variables": { ... all captured vars ... }
  }
```

`{{webhook_url}}` and `{{webhook_secret}}` are placeholders the wizard
asks the user to paste from the agent's settings page after the
template imports. They are unique per-agent — see
`docs/phase-13-multitenancy.md` for the URL structure.

### 3. Recommended flow shape

- Greeting / consent
- Name → email → company → phone (one at a time, with validation)
- Qualification question(s) — set `qualified=true` + a `score` (0–100)
- Fire the Custom Action above
- Confirmation message
- `End` block

## Building the template (one-time, Voiceflow-side)

1. Sign in to https://creator.voiceflow.com
2. New Project → Conversational Agent.
3. Build the flow per the shape above. Use Voiceflow's
   "Set" blocks for the four lead variables and the "Custom Action"
   block for the POST.
4. Test inside the Voiceflow IDE.
5. Project menu → **Export project (.vf)**.
6. Drop the exported file at
   `public/templates/lead-qualification.vf` in this repo.

## Why we can't auto-generate it

Voiceflow's `.vf` export is an opaque binary format tied to specific
Voiceflow internals (canvas coordinates, block IDs, model references).
There's no public schema we can hand-roll against. The pragmatic path
is to build it once in their IDE and ship the binary.

If Voiceflow ever ships an OpenAPI for project creation, we can revisit
and provision agents programmatically inside our onboarding flow —
that's tracked as future work (Phase H+ in the multitenancy doc).
