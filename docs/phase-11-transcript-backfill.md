# Phase 11 — Voiceflow transcript backfill

Pull conversations that happened **inside Voiceflow** (the preview chat, the
embedded widget, or anything before local storage existed) into the [[phase-6-conversation-storage|local
conversation store]] via [[docs/voiceflow/transcripts/README|Voiceflow's Transcript (Analytics) API]].

## What shipped

- **Transcript API client** in `VoiceflowService`:
  - `searchTranscripts($take, $skip, $filters)` →
    `POST {analytics}/v1/transcript/project/{projectID}`
  - `getTranscript($id)` → `GET {analytics}/v1/transcript/{id}?filterConversation=true`
  - `transcriptMessages($transcript)` → flattens a transcript's `logs`
    (`action` = user text, `trace` of type text/speak = agent) into messages.
  - The Analytics API lives on a **separate host**
    (`analytics-api.voiceflow.com`), authed with the raw `VF.DM` key.
- **`voiceflow:backfill` command** — searches recent transcripts, fetches each,
  and creates `conversations` + `messages` via `ConversationRecorder`.
  **Idempotent**: keyed on `voiceflow_transcript_id`, so re-runs only add new
  ones.
- `VOICEFLOW_ANALYTICS_URL` config/env.
- Tests (HTTP-faked): log→message parsing, import, idempotency.

## Usage

```bash
# Single team (auto-detected if only one), 50 most recent transcripts:
php artisan voiceflow:backfill --team=1 --take=50 --skip=0
```

> Note: Voiceflow transcripts are **not auto-saved** — only conversations that
> were ended/persisted on Voiceflow's side appear in the search. [[phase-5-voiceflow|Conversations
> already captured live through `/chat`]] are skipped (matched by transcript id).
> ([[phase-14-public-stats|`/agent` was renamed to `/chat` in Phase 14]].)

## Scheduling (optional)

To keep the local store in sync automatically, schedule it in
`routes/console.php` or `app/Console/Kernel.php`:

```php
Schedule::command('voiceflow:backfill --team=1')->hourly();
```

## API contract used

| Call | Endpoint |
| ---- | -------- |
| Search | `POST https://analytics-api.voiceflow.com/v1/transcript/project/{projectID}?take=&skip=&order=DESC` |
| Get | `GET https://analytics-api.voiceflow.com/v1/transcript/{transcriptID}?filterConversation=true` |

Auth: `authorization: <VF.DM key>` (raw, no Bearer).
See <https://docs.voiceflow.com/api-reference/transcript/search-transcripts> and
<https://docs.voiceflow.com/api-reference/transcript/get-transcript>.
