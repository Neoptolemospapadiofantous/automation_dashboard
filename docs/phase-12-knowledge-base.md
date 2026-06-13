# Phase 12 — Knowledge Base

Ground the agent (and the dashboard) in your own content — pricing, FAQs,
product docs — via the knowledge-base API the runtime exposed at the time
(legacy-engine specifics; see the archived reference under
[[docs/voiceflow/knowledge-base/README|docs/voiceflow/]]).

## What shipped

- **[[phase-5-voiceflow|the legacy-engine client's KB methods]]** (the
  client has since been superseded by the native runtime in `app/Runtime/`):
  - `listKbDocuments($page, $limit)` — list KB documents
  - `createKbUrlDocument($url, $name)` — add a doc by scraping a URL
  - `queryKnowledgeBase($question, $chunkLimit, $synthesis)` →
    synthesized answer + source chunks
- **`KnowledgeBaseController`** + routes: `/knowledge` (list + UI),
  `POST /knowledge/url` (add a doc), `POST /knowledge/query` (ask).
- **Knowledge UI** (`Knowledge/Index.vue`): add URLs, see document status
  (PENDING/SUCCESS/ERROR), and ask the KB a question with sourced answers.
- **"Knowledge" nav link**; KB host config/env.
- Tests (HTTP-faked): list, add-url, query, 503-when-unconfigured.

## Hosts

At the time, list/create-document and query ran against the legacy engine's
hosted KB endpoints, each authed with the engine's raw API key. The query
body carried the project id, the question, and the environment alias.
(Legacy-engine specifics; see the archived reference under
[[docs/voiceflow/knowledge-base/README|docs/voiceflow/]].)

## Usage

1. Open **Knowledge** in the nav.
2. Add a URL (e.g. your pricing page) — it gets scraped + chunked
   (status goes PENDING → SUCCESS).
3. Ask a question; you get a synthesized answer plus the source chunks.
4. The same KB powers [[phase-5-voiceflow|the agent's answers during lead conversations]].

## Next ideas

> **Note (auto-synced 2026-06-05):** File upload, delete, inspect, and
> type filter shipped in `00c83fd` (per-agent KB UX). Current
> KB routes: `/knowledge` (index), `POST /knowledge/url`,
> `POST /knowledge/file`, `POST /knowledge/query`,
> `GET /knowledge/{documentID}` (inspect), `DELETE /knowledge/{documentID}`.
> See `routes/web.php` and `KnowledgeBaseController`.

- Per-document metadata for agent KB filtering.
