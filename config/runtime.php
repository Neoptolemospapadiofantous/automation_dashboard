<?php

/**
 * Native runtime configuration. Reads env so prod/staging/dev can swap
 * models and RAG defaults without code changes.
 *
 * See app/Runtime/ for the implementation — the native engine is the only
 * engine; agents.runtime_mode remains as the seam for any future engine.
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
        // ChatGPT tier. Reuses the OPENAI_API_KEY already present for
        // embeddings unless RUNTIME_OPENAI_API_KEY overrides it.
        'openai' => [
            'api_key' => env('RUNTIME_OPENAI_API_KEY', env('OPENAI_API_KEY')),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
            'model_default' => env('RUNTIME_TIER_GPT_MODEL', 'gpt-5.1'),
        ],
        'google' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'model_default' => env('RUNTIME_TIER_GEMINI_MODEL', 'gemini-2.5-flash'),
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
        // Confidence floor for *answering*. When an agent HAS a knowledge
        // base but the best retrieved chunk scores below this, the turn is
        // treated as low-confidence: the model is told not to guess and to
        // escalate, with a deterministic handoff backstop (see FlowExecutor).
        // Higher than min_similarity on purpose — "good enough to inject" is
        // a lower bar than "good enough to answer on". Per-agent opt-out via
        // agents.auto_escalate_low_confidence.
        'answer_confidence' => (float) env('RUNTIME_ANSWER_CONFIDENCE', 0.45),
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
    |
    | VERIFIED 2026-06-11 against official provider pages (see
    | docs/operations/pricing-audit.md): all five model IDs valid, all
    | five rate pairs exact. Re-verify when bumping any model env.
    */
    'tiers' => array_merge([
        'haiku' => [
            'provider' => 'anthropic',
            'label' => 'Claude Haiku',
            'description' => 'Fastest replies at the lowest cost. Excellent for FAQ answering, lead capture, and high-traffic sites.',
            'model' => env('RUNTIME_TIER_HAIKU_MODEL', 'claude-haiku-4-5-20251001'),
            'credits_per_message' => (int) env('RUNTIME_TIER_HAIKU_CREDITS', 1),
            'pricing_per_mtok' => ['in' => 1.00, 'out' => 5.00],
        ],
        'sonnet' => [
            'provider' => 'anthropic',
            'label' => 'Claude Sonnet',
            'description' => 'Smarter conversations with deeper reasoning. Best for complex products, nuanced qualification, and longer sales cycles.',
            'model' => env('RUNTIME_TIER_SONNET_MODEL', 'claude-sonnet-4-6'),
            'credits_per_message' => (int) env('RUNTIME_TIER_SONNET_CREDITS', 3),
            'pricing_per_mtok' => ['in' => 3.00, 'out' => 15.00],
        ],
        'opus' => [
            'provider' => 'anthropic',
            'label' => 'Claude Opus',
            'description' => 'The most capable model. Expert-grade reasoning for technical sales, high-stakes conversations, and premium experiences.',
            'model' => env('RUNTIME_TIER_OPUS_MODEL', 'claude-opus-4-8'),
            'credits_per_message' => (int) env('RUNTIME_TIER_OPUS_CREDITS', 10),
            'pricing_per_mtok' => ['in' => 5.00, 'out' => 25.00],
        ],
        'gpt' => [
            'provider' => 'openai',
            'label' => 'ChatGPT',
            'description' => 'OpenAI\'s flagship model. Strong general reasoning and a familiar conversational style.',
            'model' => env('RUNTIME_TIER_GPT_MODEL', 'gpt-5.1'),
            'credits_per_message' => (int) env('RUNTIME_TIER_GPT_CREDITS', 3),
            'pricing_per_mtok' => ['in' => 1.25, 'out' => 10.00],
        ],
        'gemini' => [
            'provider' => 'google',
            'label' => 'Gemini',
            'description' => 'Google\'s fast multimodal model. Snappy answers at low cost with solid factual recall.',
            'model' => env('RUNTIME_TIER_GEMINI_MODEL', 'gemini-2.5-flash'),
            'credits_per_message' => (int) env('RUNTIME_TIER_GEMINI_CREDITS', 1),
            'pricing_per_mtok' => ['in' => 0.30, 'out' => 2.50],
        ],
    ], (string) env('APP_ENV') === 'local' ? [
        // LOCAL ONLY: a development tier backed by the local Ollama server
        // (routed through the OpenAI-compatible client → OPENAI_BASE_URL).
        // The env gate means this tier is evaluated out of the config in any
        // non-local environment, so it can never appear on prod.
        'local' => [
            'provider' => 'openai',
            'label' => 'Local (Ollama)',
            'description' => 'Local Ollama model for development — never shown in production.',
            'model' => env('RUNTIME_TIER_LOCAL_MODEL', env('RUNTIME_TIER_GPT_MODEL', 'qwen2.5')),
            'credits_per_message' => (int) env('RUNTIME_TIER_LOCAL_CREDITS', 1),
            'pricing_per_mtok' => ['in' => 0.0, 'out' => 0.0],
        ],
    ] : []),

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
