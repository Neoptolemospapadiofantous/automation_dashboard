# Phase 11 — Legacy-engine transcript backfill

Pull conversations that happened **inside the legacy engine** (its preview
chat, the embedded widget, or anything before local storage existed) into the
[[phase-6-conversation-storage|local conversation store]] via the legacy
engine's transcript/analytics API (legacy-engine specifics — the
`docs/voiceflow/` archive that documented them was removed from the repo in
`76ee819`; git history only).

## What shipped

- **Transcript API client** in the legacy-engine client (since replaced by
  the native runtime in `app/Runtime/`):
  - `searchTranscripts($take, $skip, $filters)` — search a project's transcripts
  - `getTranscript($id)` — fetch one filtered transcript
  - `transcriptMessages($transcript)` → flattens a transcript's `logs`
    (`action` = user text, `trace` of type text/speak = agent) into messages.
  - The analytics API lived on a **separate host** from the main engine,
    authed with the engine's raw API key.
- **The transcript-backfill command** (since removed with the legacy
  engine) — searched recent transcripts, fetched each, and created
  `conversations` + `messages` via `ConversationRecorder`.
  **Idempotent**: keyed on `transcript_id`, so re-runs only added new ones.
- Analytics-host config/env.
- Tests (HTTP-faked): log→message parsing, import, idempotency.

## Usage (historical)

The command took `--team`, `--take`, and `--skip` options (e.g. the most
recent 50 transcripts for a single team) and could be scheduled hourly in
`routes/console.php` to keep the local store in sync.

> Note: legacy-engine transcripts were **not auto-saved** — only conversations
> that were ended/persisted on the engine's side appeared in the search.
> [[phase-5-voiceflow|Conversations already captured live through `/chat`]] were
> skipped (matched by transcript id).
> ([[phase-14-public-stats|`/agent` was renamed to `/chat` in Phase 14]].)

## API contract used

The backfill spoke to the legacy engine's analytics host: a search call
(`POST .../transcript/project/{projectID}`, paged via `take`/`skip`) and a
get call (`GET .../transcript/{transcriptID}`), both authed with the engine's
raw API key in the `authorization` header (no Bearer). Full endpoint shapes
are legacy-engine specifics — the `docs/voiceflow/` archive that held them
was removed from the repo in `76ee819` (git history only).
