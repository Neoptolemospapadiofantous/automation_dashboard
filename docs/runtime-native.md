# Native runtime — the Flowstack-owned conversational engine

> Status: **the only engine.** The entire Voiceflow surface (services,
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

- **Credits are charged by controllers, never by the engine.** Same math both
  engines: 1 + number of agent replies per turn.
- **Transitions are owned by FlowExecutor** via each state's `onToolSuccess`
  map / `autoNext`. Tools never write `flow_state`.
- **Lead capture happens inside the engine** (`capture_lead` tool → leads
  table + `LeadSaved` broadcast). Controllers do no variable extraction.
- Trace shape is Voiceflow-compatible (`{type:'text', payload:{message}}`) so
  every existing UI renders unchanged.

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
# Flip an existing agent to native
php artisan tinker --execute="\App\Models\Agent::where('slug','SLUG')->first()->forceFill(['runtime_mode'=>'native','status'=>'active'])->save();"

# Health (also: the health button on /agents/{slug})
# → {ok, engine: 'native', llm_model, embedding_model}

# Housekeeping (scheduled daily)
php artisan runtime:prune-sessions
```

## Quality tiers (per-agent model choice)

Customers pick a TIER per agent on the Versions page — never a raw model
name. Tier couples model to credit price so margin survives by design:

| Tier | Model (env-overridable) | Credits/msg | Provider $/MTok in/out |
|---|---|---|---|
| Claude Haiku | claude-haiku-4-5 | 1 | $1 / $5 |
| Claude Sonnet | claude-sonnet-4-6 | 3 | $3 / $15 |
| Claude Opus | claude-opus-4-8 | 10 | $5 / $25 |

Legacy tier keys (standard/enhanced) alias to haiku/sonnet — published
rows from before the lineup keep working without a data migration.

The tier rides the published config (draft → publish → rollback like any
behavior change). Unknown/absent tiers degrade to Standard. runtime_usage
keeps per-tier token buckets so `runtime:costs` prices each correctly.

Per-session token usage accumulates in `runtime_sessions.variables`
(`_tokens_in` / `_tokens_out`) for cost observability.

Handoffs: when a visitor asks for a human, the `request_handoff` tool flags
the session AND notifies the team owner (bell + email,
`HandoffRequestedNotification`).

## Known follow-ups

- pgvector swap for `kb_chunks.embedding` (JSON + in-process cosine is fine
  to ~10k chunks/agent; see migration comments in
  `2026_06_11_000002_create_kb_chunks_table.php`)
- Token-level SSE streaming (currently stage-level events; the UIs consume
  whole messages)
- Per-agent flow selection (every agent runs `LeadCaptureFlow` today; a
  `flow` column + template registry is the planned shape)

## Economics note

`docs/operations/economics.md` predates this engine (Voiceflow-plan-based).
Native cost basis: ~$0.005–0.01 per customer message (Haiku + embeddings) →
~99% gross margin at the $99 Starter price. A rewrite of the economics doc is
pending; until then treat it as historical.
