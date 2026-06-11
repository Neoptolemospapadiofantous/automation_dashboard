# Agent lifecycle — operator guide

The full journey from signup to captured leads. The dashboard shows a live
version of this as the **setup checklist** (it self-completes as you work
and disappears when everything is done).

```
Phase 0   platform keys          (you, once)
Phase 1   signup → onboarding    (~60s, agent live instantly)
Phase 2   teach it               /knowledge
Phase 3   shape it               /agents/versions
Phase 4   test it                /chat
Phase 5   ship it                /install
Phase 6   harvest                /leads · /conversations · analytics
Phase 7   operate & iterate      health · billing · multiple agents
```

---

## Phase 0 — Platform prerequisites (once)

```bash
# .env
ANTHROPIC_API_KEY=sk-ant-...   # console.anthropic.com — the chat loop
OPENAI_API_KEY=sk-...          # platform.openai.com — KB embeddings (pennies)

php artisan config:clear
```

Agents *create* fine without these, but chat returns 503 until they're set.
The health button on `/agents/{slug}` reports exactly which key is missing.
This is the only infrastructure an agent needs — no external accounts, no
provisioning.

## Phase 1 — Signup & onboarding (~60 seconds)

| Step | What happens under the hood |
|---|---|
| Register | Personal team created; Starter plan grants 2,500 monthly credits |
| Verify email | Branded mail; in dev the signed link is in `storage/logs/laravel.log` |
| Onboarding wizard | Agent name + 4 optional profile questions (segmentation) |
| "Set up my agent" | `CreateAgent`: row with `status=active`, `runtime_mode=native`, auto slug; becomes the team's current agent. **Nothing external happens** — live the moment the row exists |
| Done page | Shows the embed snippet with the slug baked in |

## Phase 2 — Teach it (`/knowledge`)

Three ways in: **paste text**, **fetch a URL**, **upload a file**
(PDF/TXT/MD/CSV, 10 MB). Each document is chunked (~500 tokens with
overlap), embedded via OpenAI, and stored per-agent.

Test retrieval immediately with the **Q&A box** — it runs the same cosine
search the agent uses plus an LLM-synthesized, citation-backed answer.

At chat time retrieval happens twice over: top-3 chunks for the visitor's
message are auto-injected into the system prompt every turn, *and* the
model can call the `query_kb` tool for deeper digs.

## Phase 3 — Shape it (`/agents/versions`)

1. Edit the **draft**: custom instructions (every turn) + greeting guidance
   (first message only).
2. **Publish** → live on the very next chat message. No deploy.
3. Iterate freely: history is linear; **Restore** any old version into the
   draft and republish to roll back; **Export JSON** for support tickets or
   copying between agents.

Drafts are invisible to the engine — only the published version is injected.

Example instructions that meaningfully change behavior:

> We sell to dental clinics. Always ask about practice size before asking
> for contact details. Never discuss competitor pricing. If they mention
> "enterprise", prioritize booking a call over email capture.

## Phase 4 — Test it (`/chat`)

Talk to the agent the way a lead would. Per turn the engine runs:

```
session load → system prompt (identity + YOUR instructions + state
objective + remembered facts + KB chunks) → Anthropic → tool loop
(capture_lead · query_kb · set_variable · request_handoff · end_session)
→ bill 1 + replies → record transcript → broadcast to the team
```

Verify: KB answers are grounded; the agent asks for contact info naturally
in discovery; saying *"I'm Jane, jane@x.co"* makes a card appear on
`/leads` live.

## Phase 5 — Ship it (`/install`)

```html
<script src="https://yourapp.com/widget/{slug}.js" defer></script>
```

Floating button → iframe chat → same engine via the public embed
endpoints. Visitor identity is a 30-day cookie. Billing differs from the
dashboard **on purpose**: the greeting is free, then 1 flat credit per
visitor message. Only `active` agents serve; endpoints are IP-throttled.

## Phase 6 — Harvest

- **`/leads`** — every `capture_lead` lands as a kanban card (status New,
  scored 0–100, deduped by email). Drag, assign (assignee gets bell +
  email), notes autosave.
- **Handoffs** — a visitor asking for a human flags the session AND emails
  the team owner (`HandoffRequestedNotification`).
- **`/conversations`** — searchable transcripts, linked to leads.
- **`/agents/{slug}/analytics`** — volume, funnel, heatmap.
- **`/billing`** — credits drain per message; burn alerts at 50%/80%;
  Stripe top-ups + upgrades; out of credits = clean 402 in chat,
  "unavailable" on embed.

## Phase 7 — Operate & iterate

- **Health**: `/agents/{slug}` → health button → `{ok, engine, models}`.
- **Everything applies live**: Knowledge + Versions need no redeploys.
- **Multiple agents**: plan-capped (Starter 1, Operator 5); the picker
  swaps every page's scope (leads, KB, versions, analytics).
- **Housekeeping**: idle runtime sessions prune after 30 days (scheduled).
- **Delete**: danger zone on the agent page. KB + sessions cascade;
  leads/conversations are preserved but unlinked.

---

## The critical path

> register → verify → name the agent → paste 1 KB doc → publish 1
> instruction set → copy the snippet

Five minutes end-to-end. Phases 2–3 are skippable — the agent runs on
sane defaults without them — but they're where generic chat becomes *your*
agent.

See also: [runtime-native.md](./runtime-native.md) for engine architecture
and configuration, [operations/launch-checklist.md](./operations/launch-checklist.md)
for the platform-level launch runbook.
