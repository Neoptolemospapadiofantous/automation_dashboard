# Phase 5 — Voiceflow agent (server-proxied Dialog Manager API)

Lead conversations are handled by a Voiceflow agent, proxied entirely
server-side so the API key never reaches the browser. Captured fields become
leads on the board live.

## What this phase delivers

- **`VoiceflowService`** — a server-side client for the Dialog Manager
  (Conversations) API: `launch`, `sendText`, low-level `interact`,
  `getVariables`, plus `parseTraces` (text/choice/end) and `extractLeadFields`.
  API key (prefix `VF.DM.*`) is read from config and never exposed.
- **`VoiceflowController`** (`/agent/launch`, `/agent/interact`) — proxies the
  conversation, parses traces into chat messages + quick-reply buttons, reads
  the agent's session variables, and **upserts a team-scoped `Lead`** from the
  captured fields. Broadcasts `LeadMessage` (live transcript) and `LeadSaved`
  (board updates).
- **`VoiceflowWebhookController`** (`POST /api/voiceflow/lead-captured`) — an
  inbound webhook for Voiceflow **Custom Actions** to push a qualified lead the
  instant it's captured, secured by a shared-secret header
  (`X-Webhook-Secret` = `VOICEFLOW_WEBHOOK_SECRET`).
- **`LeadMessage`** broadcast event on the private `team.{id}` channel.
- **Vue chat panel** (`Agent/Index.vue`) — start a conversation, send replies,
  tap quick-reply buttons, and watch captured lead fields populate live with a
  link through to the board.
- **"Agent" nav link**; feature tests with HTTP faking.

## Configuration

```env
VOICEFLOW_API_KEY=VF.DM.xxxxxxxx        # Voiceflow → Settings → API keys
VOICEFLOW_VERSION_ID=production
VOICEFLOW_PROJECT_ID=...
VOICEFLOW_RUNTIME_URL=https://general-runtime.voiceflow.com
VOICEFLOW_API_URL=https://api.voiceflow.com
VOICEFLOW_WEBHOOK_SECRET=...            # also set on the agent's webhook header
```

Which session variables become lead fields is controlled by
`config/services.php → voiceflow.lead_variables` (default: name, email, phone,
company). Without an API key the agent page shows a configured=false notice and
the endpoints return 503.

## API contract used

- **Interact:** `POST {runtime}/state/user/{userID}/interact` with
  `{ action: { type: 'launch' } }` or `{ action: { type: 'text', payload } }`,
  headers `Authorization` + `versionID`. Returns an array of trace objects.
- **Variables:** `GET {runtime}/state/user/{userID}/variables`.

See <https://docs.voiceflow.com/reference/stateinteract-1> and
<https://docs.voiceflow.com/reference/trace-types>.

## Verify

```bash
php artisan test --filter=VoiceflowTest
pnpm run build
```

## Next

Phase 6: delegation engine (assignment rules, presence) and a Transcripts API
backfill for full conversation history/audit.
