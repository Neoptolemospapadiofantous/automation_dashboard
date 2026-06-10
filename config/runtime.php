<?php

/**
 * Native runtime configuration. Reads env so prod/staging/dev can swap
 * LLM providers, embedding models, and RAG defaults without code changes.
 *
 * See app/Runtime/ for the implementation. The runtime is feature-flagged
 * per-agent via agents.runtime_mode — production stays on 'voiceflow' until
 * we explicitly flip an agent.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | LLM provider
    |--------------------------------------------------------------------------
    |
    | The primary LLM used for the conversational loop. Currently 'anthropic'
    | (Claude) is the only implemented client; openai is a planned alternate.
    |
    | model_default   — used for the bulk of routine turns (cheap + fast)
    | model_complex   — used when the flow asks for a more capable model
    |                   (e.g. qualifying messy intent, summarizing transcripts)
    */
    'llm' => [
        'provider' => env('RUNTIME_LLM_PROVIDER', 'anthropic'),

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model_default' => env('ANTHROPIC_MODEL_DEFAULT', 'claude-haiku-4-5-20251001'),
            'model_complex' => env('ANTHROPIC_MODEL_COMPLEX', 'claude-sonnet-4-6'),
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
        'provider' => env('RUNTIME_EMBEDDINGS_PROVIDER', 'openai'),
        'model' => env('RUNTIME_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('RUNTIME_EMBEDDINGS_DIMENSIONS', 1536),
        'openai_api_key' => env('OPENAI_API_KEY'),
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
    ],

];
