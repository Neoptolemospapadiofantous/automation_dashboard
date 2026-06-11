# Native runtime — the Flowstack-owned conversational engine

> Status: **default engine for new agents** since branch `runtime-native-l1`
> (`RUNTIME_DEFAULT_MODE=native`). Voiceflow remains as a legacy adapter for
> existing `runtime_mode='voiceflow'` agents only.

## Architecture

```
Controller (chat / embed)                    bills credits AROUND the engine
   └─ Runtime contract  ←──────────────  app(Runtime::class)
        └─ RuntimeDispatcher             routes by agents.runtime_mode
             ├─ AgentRuntime  (native)   launch / sendText / streamText / endSession / health
             │    ├─ SessionManager      runtime_sessions: state + variables + LLM history
             │    ├─ FlowExecutor        the loop: state → system prompt (+auto-RAG) →
             │    │                      Anthropic complete → tool dispatch → transition
             │    │    ├─ AnthropicClient   POST /v1/messages, tool calling, retries
             │    │    ├─ ToolRegistry      capture_lead · query_kb · set_variable ·
             │    │    │                    request_handoff · end_session
             │    │    └─ KnowledgeBase     chunk → embed (OpenAI) → cosine top-k
             │    └─ LeadCaptureFlow     greeting → discovery → wrapup → ended
             └─ VoiceflowAdapter (legacy) wraps VoiceflowService; delete after migration
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
| `RUNTIME_DEFAULT_MODE` | engine for NEW agents (`native` \| `voiceflow`) | `voiceflow` in code, `native` in `.env` |
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

Per-session token usage accumulates in `runtime_sessions.variables`
(`_tokens_in` / `_tokens_out`) for cost observability.

Handoffs: when a visitor asks for a human, the `request_handoff` tool flags
the session AND notifies the team owner (bell + email,
`HandoffRequestedNotification`).

## What native agents do NOT have (legacy-engine features)

- Evaluations / Environments pages (Voiceflow APIs — hidden from the sidebar
  when the current agent is native)
- /system/webhooks viewer (no upstream webhooks exist)
- File types DOCX/XLSX in the KB (native: PDF/TXT/MD/CSV + URL + text)

## Known follow-ups

- pgvector swap for `kb_chunks.embedding` (JSON + in-process cosine is fine
  to ~10k chunks/agent; see migration comments in
  `2026_06_11_000002_create_kb_chunks_table.php`)
- Token-level SSE streaming (currently stage-level events; the UIs consume
  whole messages)
- Per-agent flow selection (every agent runs `LeadCaptureFlow` today; a
  `flow` column + template registry is the planned shape)
- Deleting the entire Voiceflow surface once the last legacy agent migrates —
  the adapter docblock (`VoiceflowAdapter.php`) lists the one-commit plan.

## Economics note

`docs/operations/economics.md` predates this engine (Voiceflow-plan-based).
Native cost basis: ~$0.005–0.01 per customer message (Haiku + embeddings) →
~99% gross margin at the $99 Starter price. A rewrite of the economics doc is
pending; until then treat it as historical.
