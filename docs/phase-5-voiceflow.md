# Phase 5 — Voiceflow agent (server-proxied Dialog Manager API)

Lead conversations are handled by a Voiceflow agent, proxied entirely
server-side so the API key never reaches the browser. Captured fields become
leads on the board live.

## What this phase delivers

- **`VoiceflowService`** — a server-side client for the Dialog Manager
  (Conversations) API: `launch`, `sendText`, low-level `interact`,
  `getVariables`, plus `parseTraces` (text/choice/end) and `extractLeadFields`.
  API key (prefix `VF.DM.*`) is read from config and never exposed.
- **`VoiceflowController`** (`/chat/launch`, `/chat/interact`) — proxies the
  conversation, parses traces into chat messages + quick-reply buttons, reads
  the agent's session variables, and **[[phase-3-leads|upserts a team-scoped `Lead`]]** from the
  captured fields. Broadcasts `LeadMessage` (live transcript) and `LeadSaved`
  (board updates).

  > [[phase-14-public-stats|Routes were renamed from `agent.*` → `chat.*` in Phase 14]] to
  > disambiguate from the agents-CRUD routes (`agents.*`). Same controller,
  > same behaviour, new URL + route name.
- **`VoiceflowWebhookController`** (`POST /api/voiceflow/lead-captured/{agent:slug}`)
  — an inbound webhook for Voiceflow **Custom Actions** to push a qualified
  lead the instant it's captured, secured by a per-agent shared-secret header
  (`X-Webhook-Secret` = `Agent::$webhook_secret`).

  > [[phase-13-multitenancy|Phase 13 made this per-agent (was app-wide in Phase 5)]].
- **`LeadMessage`** broadcast event on the private `team.{id}` channel.
- **[[docs/voiceflow/README|Vue chat panel]]** (`Pages/Chat/Index.vue`) — start a conversation, send
  replies, tap quick-reply buttons, and watch captured lead fields populate
  live with a link through to the board.
- **"Chat" nav link**; feature tests with HTTP faking.

## Configuration

```env
VOICEFLOW_API_KEY=VF.DM.xxxxxxxx        # Voiceflow → agent settings → API key
VOICEFLOW_PROJECT_ID=...                # agent settings (required for V4)
VOICEFLOW_ENVIRONMENT=main              # V4 environment alias (was version id)
VOICEFLOW_RUNTIME_URL=https://general-runtime.voiceflow.com
VOICEFLOW_API_URL=https://api.voiceflow.com
VOICEFLOW_WEBHOOK_SECRET=...            # also set on the agent's webhook header
```

Which session variables become lead fields is controlled by
`config/services.php → voiceflow.lead_variables` (default: name, email, phone,
company). Without an API key + project id the agent page shows a
configured=false notice and the endpoints return 503.

## API contract used (V4 Conversations API)

V4 is a **two-step** flow keyed on projectID + environment (alias `main`); the
legacy `/state/user/...` + `versionID` endpoints are deprecated.

1. **Start session:** `POST {runtime}/v4/project/{projectID}/environment/{env}/session`
   with header `authorization: <VF.DM key>` and body `{ "userID": "..." }`
   → returns `{ "sessionKey": "..." }`.
2. **Interact:** `POST {runtime}/v4/interact` with header
   `authorization: <sessionKey>` and body
   `{ "action": { "type": "launch" | "text", "payload": ... }, "variables": {} }`
   → returns `{ "traces": [...] }`.

`VoiceflowService` caches the per-user `sessionKey` (1h) so multi-turn chats
reuse the session; a `launch` resets it. `/chat/health` runs both steps and
reports exactly which one fails.

See <https://docs.voiceflow.com/api-reference/conversations-api/overview> and
<https://docs.voiceflow.com/trace-types>.

## Verify

```bash
php artisan test --filter=VoiceflowTest
pnpm run build
```

## Next

[[phase-7-delegation|Phase 6: delegation engine (assignment rules, presence) and a Transcripts API backfill]]
for full conversation history/audit.
