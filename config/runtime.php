<?php

/**
 * Native runtime configuration. Reads env so prod/staging/dev can swap
 * models and RAG defaults without code changes.
 *
 * See app/Runtime/ for the implementation — the native engine is the only
 * engine (Voiceflow was fully removed); agents.runtime_mode remains as the
 * seam for any future engine.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | LLM
    |--------------------------------------------------------------------------
    |
    | model_default — used for every turn (cheap + fast; raise to Sonnet
    |                 via ANTHROPIC_MODEL_DEFAULT if quality demands it).
    */
    'llm' => [
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'model_default' => env('ANTHROPIC_MODEL_DEFAULT', 'claude-haiku-4-5-20251001'),
            'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 1024),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings + Knowledge Base
    |--------------------------------------------------------------------------
    |
    | OpenAI's text-embedding-3-small is the cheapest decent model at
    | $0.02 / 1M tokens, 1536 dimensions, accurate for short-form chat
    | retrieval. Swap to text-embedding-3-large or voyage-3 if quality
    | benchmarks demand it.
    */
    'embeddings' => [
        'model' => env('RUNTIME_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('RUNTIME_EMBEDDINGS_DIMENSIONS', 1536),
        'openai_api_key' => env('OPENAI_API_KEY'),
        'openai_base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
    ],

    'rag' => [
        'chunk_size_tokens' => (int) env('RUNTIME_CHUNK_SIZE', 500),
        'chunk_overlap_tokens' => (int) env('RUNTIME_CHUNK_OVERLAP', 50),
        'retrieval_top_k' => (int) env('RUNTIME_TOP_K', 5),
        // Similarity threshold below which retrieved chunks are dropped —
        // prevents irrelevant context from polluting prompts.
        'min_similarity' => (float) env('RUNTIME_MIN_SIMILARITY', 0.25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-tenant safety rails
    |--------------------------------------------------------------------------
    |
    | Hard caps that prevent a single conversation (intentional abuse or
    | runaway loop) from burning the customer's credit balance.
    |
    | max_tool_calls_per_turn: how many tool calls the LLM can chain
    |   inside one user turn before we force a stop. Real agents need
    |   ~2-3; >10 indicates a loop.
    |
    | max_turns_per_session: hard ceiling on session length. Realistic
    |   conversations end around turn 15-20; 100 is generous.
    */
    'safety' => [
        'max_tool_calls_per_turn' => (int) env('RUNTIME_MAX_TOOL_CALLS', 10),
        'max_turns_per_session' => (int) env('RUNTIME_MAX_TURNS', 100),
        // Embed greetings are free (the visitor hasn't said anything yet),
        // which makes them a token-burn vector for bots spread across IPs.
        // Beyond this many launches per team per day, launch() debits one
        // credit like any other turn. 500 ≈ a very busy small site.
        'free_greetings_per_day' => (int) env('RUNTIME_FREE_GREETINGS_PER_DAY', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quality tiers — the user-facing model choice
    |--------------------------------------------------------------------------
    |
    | Customers pick a model per agent (onboarding + Versions page). Each
    | tier couples a model to a credit price so margin survives by
    | construction: smarter model ⇒ more credits per message. Legacy tier
    | keys 'standard'/'enhanced' (pre-lineup) alias to haiku/sonnet in
    | AgentConfigVersion::publishedTier.
    |
    | pricing_per_mtok feeds ONLY the runtime:costs margin report — never
    | billing (customers pay credits, not tokens).
    */
    'tiers' => [
        'haiku' => [
            'label' => 'Claude Haiku',
            'description' => 'Fastest replies at the lowest cost. Excellent for FAQ answering, lead capture, and high-traffic sites.',
            'model' => env('RUNTIME_TIER_HAIKU_MODEL', 'claude-haiku-4-5-20251001'),
            'credits_per_message' => (int) env('RUNTIME_TIER_HAIKU_CREDITS', 1),
            'pricing_per_mtok' => ['in' => 1.00, 'out' => 5.00],
        ],
        'sonnet' => [
            'label' => 'Claude Sonnet',
            'description' => 'Smarter conversations with deeper reasoning. Best for complex products, nuanced qualification, and longer sales cycles.',
            'model' => env('RUNTIME_TIER_SONNET_MODEL', 'claude-sonnet-4-6'),
            'credits_per_message' => (int) env('RUNTIME_TIER_SONNET_CREDITS', 3),
            'pricing_per_mtok' => ['in' => 3.00, 'out' => 15.00],
        ],
        'opus' => [
            'label' => 'Claude Opus',
            'description' => 'The most capable model. Expert-grade reasoning for technical sales, high-stakes conversations, and premium experiences.',
            'model' => env('RUNTIME_TIER_OPUS_MODEL', 'claude-opus-4-8'),
            'credits_per_message' => (int) env('RUNTIME_TIER_OPUS_CREDITS', 10),
            'pricing_per_mtok' => ['in' => 5.00, 'out' => 25.00],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-tenant safety rails
    |--------------------------------------------------------------------------
    |
    | Hard caps that prevent a single conversation (intentional abuse or
    | runaway loop) from burning the customer's credit balance.
    |
    | max_tool_calls_per_turn: how many tool calls the LLM can chain
    |   inside one user turn before we force a stop. Real agents need
    |   ~2-3; >10 indicates a loop.
    |
    | max_turns_per_session: hard ceiling on session length. Realistic
    |   conversations end around turn 15-20; 100 is generous.
    */
    'safety' => [
        'max_tool_calls_per_turn' => (int) env('RUNTIME_MAX_TOOL_CALLS', 10),
        'max_turns_per_session' => (int) env('RUNTIME_MAX_TURNS', 100),
        // Embed greetings are free (the visitor hasn't said anything yet),
        // which makes them a token-burn vector for bots spread across IPs.
        // Beyond this many launches per team per day, launch() debits one
        // credit like any other turn. 500 ≈ a very busy small site.
        'free_greetings_per_day' => (int) env('RUNTIME_FREE_GREETINGS_PER_DAY', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session management
    |--------------------------------------------------------------------------
    |
    | history_limit: max LLM-format history entries kept per session. Old
    |   entries are trimmed from the FRONT (keeping the most recent context).
    |   Each turn produces 2-6 entries (user msg, assistant blocks, tool
    |   results), so 60 entries ≈ the last 10-20 turns.
    |
    | prune_days: sessions idle longer than this are deleted by the
    |   runtime:prune-sessions command (scheduled daily). Matches the
    |   30-day embed visitor cookie TTL.
    */
    'session' => [
        'history_limit' => (int) env('RUNTIME_HISTORY_LIMIT', 60),
        'prune_days' => (int) env('RUNTIME_SESSION_PRUNE_DAYS', 30),
    ],

];
