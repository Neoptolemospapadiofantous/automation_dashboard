# Phase 12 — Voiceflow Knowledge Base

Ground the agent (and the dashboard) in your own content — pricing, FAQs,
product docs — via Voiceflow's Knowledge Base API.

## What shipped

- **`VoiceflowService` KB methods**:
  - `listKbDocuments($page, $limit)` → `GET {realtime}/v1alpha1/public/knowledge-base/document`
  - `createKbUrlDocument($url, $name)` → `POST {realtime}/v1alpha1/public/knowledge-base/document` (scrapes a URL)
  - `queryKnowledgeBase($question, $chunkLimit, $synthesis)` →
    `POST {runtime}/knowledge-base/query` → synthesized answer + source chunks
- **`KnowledgeBaseController`** + routes: `/knowledge` (list + UI),
  `POST /knowledge/url` (add a doc), `POST /knowledge/query` (ask).
- **Knowledge UI** (`Knowledge/Index.vue`): add URLs, see document status
  (PENDING/SUCCESS/ERROR), and ask the KB a question with sourced answers.
- **"Knowledge" nav link**; `VOICEFLOW_REALTIME_URL` config/env.
- Tests (HTTP-faked): list, add-url, query, 503-when-unconfigured.

## Hosts (each authed with the raw VF.DM key)

| Action | Endpoint |
| ------ | -------- |
| List / Create document | `https://realtime-api.voiceflow.com/v1alpha1/public/knowledge-base/document` |
| Query | `https://general-runtime.voiceflow.com/knowledge-base/query` |

The query body sends `projectID` + `question` + `projectEnvironmentIDOrAlias`.

## Usage

1. Open **Knowledge** in the nav.
2. Add a URL (e.g. your pricing page) — Voiceflow scrapes + chunks it
   (status goes PENDING → SUCCESS).
3. Ask a question; you get a synthesized answer plus the source chunks.
4. The same KB powers the agent's answers during lead conversations.

See <https://docs.voiceflow.com/api-reference/kbpublicapidocument/create-document>,
<https://docs.voiceflow.com/api-reference/public-docs/query>.

## Next ideas

- File upload (multipart) in addition to URLs.
- Delete document + per-document metadata for agent KB filtering.
