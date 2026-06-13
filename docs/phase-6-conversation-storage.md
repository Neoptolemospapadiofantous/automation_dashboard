# Phase 6 — Conversation storage, indexing & scale

Status: **core implemented** (storage + history UI + search). [[phase-11-transcript-backfill|Legacy-engine
transcript sync]] (end transcript + properties) is the remaining follow-up.

Goal: save every conversation durably, make them searchable at scale, and keep
the legacy engine's analytics/evaluation working — without slowing the live
dashboard.

## Decisions (locked)

- **Source of truth:** **local MySQL is primary** (drives dashboard, search,
  full data ownership). Hybrid legacy-engine transcript sync is a follow-up.
- **Retention:** **keep everything forever** by default. An opt-in
  `conversations:prune --days=N --force` command exists for deliberate cleanup
  only (dry-run unless `--force`).
- **Search:** **Laravel Scout + Typesense**, configured for **hybrid keyword +
  semantic (vector) search** in one engine — Typesense auto-generates embeddings
  from message text via its built-in `ts/all-MiniLM-L12-v2` model (no external
  embedding API). Zero-infra **DB LIKE fallback** when `SCOUT_DRIVER` is unset,
  so it works locally/CI and deploys stay green before Typesense is provisioned.

## What shipped

- `conversations` + `messages` tables (team-scoped, indexed; MySQL FULLTEXT
  fallback). Models, factories.
- `ConversationRecorder` service; the chat controller records every user +
  agent turn and ends the conversation on an `end` trace.
- Inertia pages: Conversations index (paginated), Show (transcript), Search.
  "Conversations" nav link.
- `Message` is Scout-`Searchable` with a Typesense hybrid schema.
- `conversations:prune` opt-in archival command.
- Tests: recorder sequencing, one-conversation-per-user, interact persistence,
  team-scoped index.

## Data model

```
conversations
  id, team_id, lead_id (nullable FK), visitor_id,
  session_key (nullable), transcript_id (nullable),
  channel (web/agent), status (active|ended), started_at, ended_at,
  last_message_at, message_count, meta (json), timestamps
  indexes: (team_id, last_message_at), visitor_id, lead_id

messages
  id, conversation_id (FK, cascade), team_id (denormalized for scoping/search),
  role (user|agent|system), text (longtext), trace_type, payload (json),
  sequence (int), sent_at, created_at
  indexes: (conversation_id, sequence), (team_id, created_at)
  FULLTEXT(text)   -- baseline search before Typesense is wired
```

Why denormalize `team_id` onto messages: team-scoped search + retention without
a join, and Scout indexes flat documents.

## Write path (how data gets stored)

The chat controller already emits `LeadMessage` for each user/agent turn.
We add persistence alongside the broadcast:

1. **On launch:** find-or-create a `conversation` for the `visitor_id`
   (store the session key, link the lead when known).
2. **Each turn:** append `messages` rows (user message + each agent trace),
   bump `message_count`/`last_message_at`. Done in a queued listener so the HTTP
   response stays fast.
3. **On `end` trace (or inactivity):** mark conversation `ended`, and dispatch a
   job to **persist the transcript back to the legacy engine** (end transcript)
   and **tag transcript properties** (`lead_id`, `team_id`, `status`) for its
   analytics.

Refactor: extract a small `ConversationRecorder` service so the controller stays
thin and the same path is reused by the capture webhook.

## Legacy-engine sync (secondary copy + its analytics)

- **End transcript** when a conversation closes (transcripts are NOT auto-saved).
- **Transcript properties**: create definitions once (`lead_id`, `team_id`,
  `outcome`), then set values per transcript — links the engine's records to CRM.
- **Backfill/reconcile job**: nightly transcript search to fill any gaps
  (e.g. conversations that happened via the embedded widget, not our proxy).
- All legacy-engine calls go through the engine client, queued, with
  the fail-fast timeout policy already in place. (Legacy-engine specifics; see
  the archived reference under [[docs/voiceflow/transcripts/README|docs/voiceflow/]].)

## Search & indexing (scale path)

**Tier 1 — now:** Laravel Scout + Typesense.
- Make `Message` (and optionally `Conversation`) `Searchable`.
- Index: text, role, team_id, lead_id, conversation_id, sent_at.
- Typesense self-hosted on the [[phase-4-deploy|Forge]] VPS (Docker or binary) — cheap, fast,
  typo-tolerant; Scout queues indexing so writes stay fast.
- Team-scoped queries via Scout `where('team_id', ...)`.
- Fallback: MySQL `FULLTEXT(text)` works out of the box if Typesense isn't up.

**Tier 2 — later (no rework):** semantic/vector search.
- Add an embedding per message (or per conversation summary) via an embeddings
  provider; store vectors in Typesense (native vector support) or pgvector.
- Enables "find conversations about X" by meaning. Slots in behind the same
  search service interface.

## Scale & retention

- **Queued everything**: persistence, indexing, legacy-engine sync are jobs, not
  request-blocking. (Queue worker is already required for broadcasts.)
- **Partition/prune**: a configurable retention policy + `messages` pruning
  command; consider monthly table partitioning when volume is high.
- **Read path**: dashboard reads local DB; a conversation detail view streams
  messages by `sequence`. Live updates continue via the existing `LeadMessage`
  broadcast (Pusher).
- **Indexes** above keep team-scoped reads O(log n); Typesense offloads search
  from MySQL.

## UI additions

- **Conversation history** on the lead detail / agent panel (turn-by-turn).
- **Global conversation search** page (keyword now, semantic later), team-scoped.
- Live badge continues to tick new messages in via [[phase-2-realtime|Echo]].

## New config / env

```env
SCOUT_DRIVER=typesense
TYPESENSE_HOST=127.0.0.1
TYPESENSE_PORT=8108
TYPESENSE_API_KEY=...
```

## Deploy impact

- Add **Typesense** as a daemon on the Forge server (Server → Daemons), and a
  Scout import step. Falls back to MySQL fulltext if absent, so deploy stays
  green even before Typesense is provisioned.
- No change to the existing queue worker requirement.

## Build order (when approved)

1. Migrations + `Conversation`/`Message` models + relations.
2. `ConversationRecorder` + queued listener on `LeadMessage`; wire launch/interact.
3. Conversation history UI + live updates.
4. Scout + Typesense; make messages searchable; search page (MySQL-fulltext fallback first).
5. Legacy-engine transcript sync (end transcript + properties) + nightly reconcile job.
6. Retention/prune command; tests throughout.

## Open questions for you

- Retention period (e.g. keep messages 12 months, then prune)?
- Provision Typesense now, or ship Tier-1 on MySQL fulltext and add Typesense in a follow-up?
- Do you want semantic/vector search in this phase, or keep it for [[phase-7-delegation|Phase 7]]?
