# Native runtime — the Flowstack-owned conversational engine

> Status: **the only engine.** The entire legacy-engine surface (services,
> adapter, pool, webhooks, Environments/Evaluations pages, env vars,
> schema) was deleted — git history keeps it recoverable.

## Architecture

```
Controller (chat / embed)                    bills credits AROUND the engine
   └─ Runtime contract  ←──────────────  app(Runtime::class)
        └─ AgentRuntime (bound singleton) launch / sendText / streamText / endSession / health
             │    ├─ SessionManager      runtime_sessions: state + variables + LLM history
             │    ├─ FlowExecutor        the loop: state → system prompt (+auto-RAG) →
             │    │                      Anthropic complete → tool dispatch → transition
             │    │    ├─ AnthropicClient   POST /v1/messages, tool calling, retries
             │    │    ├─ ToolRegistry      capture_lead · query_kb · set_variable ·
             │    │    │                    request_handoff · end_session
             │    │    └─ KnowledgeBase     chunk → embed (OpenAI) → cosine top-k
             │    └─ LeadCaptureFlow     greeting → discovery → wrapup → ended
```

Key invariants:

- **Credits are charged by controllers, never by the engine.** The math:
  1 + number of agent replies per turn.
- **Transitions are owned by FlowExecutor** via each state's `onToolSuccess`
  map / `autoNext`. Tools never write `flow_state`.
- **Lead capture happens inside the engine** (`capture_lead` tool → leads
  table + `LeadSaved` broadcast). Controllers do no variable extraction.
- Trace shape stays backwards-compatible with the legacy engine
  (`{type:'text', payload:{message}}`) so every existing UI renders unchanged.

## Configuration

| Env | Purpose | Default |
|---|---|---|
| `ANTHROPIC_API_KEY` | chat loop (required for native) | — |
| `OPENAI_API_KEY` | KB embeddings (required for native KB) | — |
| `ANTHROPIC_MODEL_DEFAULT` | turn model | `claude-haiku-4-5-20251001` |
| `RUNTIME_MAX_TOOL_CALLS` | per-turn tool cap (runaway guard) | 10 |
| `RUNTIME_MAX_TURNS` | per-session turn cap (cost guard) | 100 |
| `RUNTIME_TOP_K` | query_kb retrieval depth | 5 |
| `RUNTIME_HISTORY_LIMIT` / `RUNTIME_SESSION_PRUNE_DAYS` | session sizing | 60 / 30 |

## Operating it

```bash
# Activate an existing agent (every agent runs on the native engine)
php artisan tinker --execute="\App\Models\Agent::where('slug','SLUG')->first()->forceFill(['runtime_mode'=>'native','status'=>'active'])->save();"

# Health (also: the health button on /agents/{slug})
# → {ok, engine: 'native', llm_model, embedding_model}

# Housekeeping + ops (scheduled daily unless noted)
php artisan runtime:prune-sessions     # drop idle sessions (30d)
php artisan credits:grant-renewals     # annual-cycle renewals + webhook self-heal
php artisan runtime:costs              # per-team LLM cost vs revenue (manual, ops-only)
```

## Quality tiers (per-agent model choice)

Customers pick a TIER per agent on the Versions page — never a raw model
name. Tier couples model to credit price so margin survives by design:

| Tier | Provider | Model (env-overridable) | Credits/msg | $/MTok in/out |
|---|---|---|---|---|
| Claude Haiku | Anthropic | claude-haiku-4-5 | 1 | $1 / $5 |
| Claude Sonnet | Anthropic | claude-sonnet-4-6 | 3 | $3 / $15 |
| Claude Opus | Anthropic | claude-opus-4-8 | 10 | $5 / $25 |
| ChatGPT | OpenAI | gpt-5.1 | 3 | $1.25 / $10 |
| Gemini | Google | gemini-2.5-flash | 1 | $0.30 / $2.50 |

Tiers whose provider API key is missing are greyed out in the picker and
rejected by validation. The session history stays in ONE canonical
(Anthropic-shaped) format — OpenAI/Gemini clients translate on the wire
both ways, so switching an agent's provider mid-conversation keeps its
history replayable.

Legacy tier keys (standard/enhanced) alias to haiku/sonnet — published
rows from before the lineup keep working without a data migration.

The tier rides the published config (draft → publish → rollback like any
behavior change). Unknown/absent tiers degrade to Standard. runtime_usage
keeps per-tier token buckets so `runtime:costs` prices each correctly.

Per-session token usage accumulates in `runtime_sessions.variables`
(`_tokens_in` / `_tokens_out`) for cost observability.

Handoffs: when a visitor asks for a human, the `request_handoff` tool flags
the session AND notifies the team owner (bell + email,
`HandoffRequestedNotification`). A third leg — SMS via
`app/Notifications/Channels/TwilioSmsChannel` — joins when the recipient
saved a `notification_phone` on their profile and `services.twilio` is
configured (`TWILIO_SID/TOKEN/FROM`); it is config-gated and best-effort,
so the bell + email always land even if Twilio is down.

## Grounded answers & the confidence gate

Every turn auto-retrieves the top KB chunks for the visitor's message
(`FlowExecutor::retrieve()`). Two thresholds govern what happens with them:

- `runtime.rag.min_similarity` (0.25) — chunks below this are dropped as noise.
- `runtime.rag.answer_confidence` (0.45) — the floor for *answering*. "Good
  enough to inject" is a lower bar than "good enough to answer on."

**Citations.** Chunks that ground an answer are attached to the assistant
message as `messages.citations`
(`[{document_id, document_title, chunk_id, score}]`) and ride out in the text
trace's `payload.citations`. The operator transcript (`Conversations/Show.vue`)
and the embed widget render them as "Source: <title>" chips. No citations on
greetings, low-confidence, or no-KB turns.

**Hybrid auto-escalate.** When the agent HAS a knowledge base but the best
retrieved score is below `answer_confidence`, the turn is low-confidence:
1. the system prompt tells the model not to guess and to escalate;
2. `request_handoff` is added to the turn's tools even if the state didn't
   expose it;
3. a deterministic backstop — if the model didn't escalate, `FlowExecutor`
   calls `EscalateToHuman` itself (flags the session + notifies the owner via
   `HandoffRequestedNotification`).

`EscalateToHuman` (`app/Runtime/Support/`) is the single escalation path,
shared with `RequestHandoffTool` so the two can't drift. Per-agent opt-out:
`agents.auto_escalate_low_confidence` (default on). An agent with no KB never
trips the gate — a weak score there means "answers from instructions," not
"couldn't answer."

## Known follow-ups

- pgvector swap for `kb_chunks.embedding` (JSON + in-process cosine is fine
  to ~10k chunks/agent; see migration comments in
  `2026_06_11_000002_create_kb_chunks_table.php`)
- Token-level SSE streaming (currently stage-level events; the UIs consume
  whole messages)
- Per-agent flow selection (every agent runs `LeadCaptureFlow` today; a
  `flow` column + template registry is the planned shape)

## Economics note

`docs/operations/economics.md` predates this engine (it models the legacy
engine's per-plan pricing). Native cost basis: ~$0.005–0.01 per customer
message (Haiku + embeddings) →
~99% gross margin at the $99 Starter price. A rewrite of the economics doc is
pending; until then treat it as historical.
