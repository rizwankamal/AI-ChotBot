<?php

declare(strict_types=1);

/**
 * ShopPulse AI runtime policy.
 *
 * Adapted from enterprise LLM principles to this stack:
 * - Embeddings: Gemini gemini-embedding-001 @ 768-dim (not OpenAI 1536)
 * - Vectors: MySQL VECTOR / JSON + cosine (not pgvector <=>)
 * - Chat: Gemini via Prism (optional OpenAI moderation when key present)
 */
return [

    'chat' => [
        'temperature' => (float) env('AI_CHAT_TEMPERATURE', 0.0),
        'max_tokens' => (int) env('AI_CHAT_MAX_TOKENS', 1024),
        'max_tool_steps' => (int) env('AI_CHAT_MAX_TOOL_STEPS', 3),
        'history_turns' => (int) env('AI_CHAT_HISTORY_TURNS', 6),
        'top_k_products' => (int) env('AI_CHAT_TOP_K_PRODUCTS', 5),
    ],

    'rag' => [
        // Cosine distance cut-off (lower = closer). Gemini 768-dim matches often
        // land ~0.35–0.50 for true positives; 0.28 (OpenAI-oriented) was too strict.
        'max_cosine_distance' => (float) env('AI_RAG_MAX_COSINE_DISTANCE', 0.55),
        // PHP cosine candidate cap (Community MySQL has no VECTOR_DISTANCE).
        'content_vector_scan_limit' => (int) env('AI_CONTENT_VECTOR_SCAN_LIMIT', 150),
    ],

    'embedding_cache' => [
        'ttl_seconds' => (int) env('AI_EMBEDDING_CACHE_TTL', 3_600),
    ],

    'semantic_cache' => [
        'enabled' => (bool) env('AI_SEMANTIC_CACHE_ENABLED', true),
        // Cache hit when cosine distance <= threshold (~92%+ similarity).
        'max_cosine_distance' => (float) env('AI_SEMANTIC_CACHE_MAX_DISTANCE', 0.08),
        'ttl_seconds' => (int) env('AI_SEMANTIC_CACHE_TTL', 86_400),
    ],

    'exact_cache' => [
        'ttl_seconds' => (int) env('AI_EXACT_CACHE_TTL', 86_400),
        'store' => env('CHAT_CACHE_STORE', env('CACHE_STORE', 'array')),
    ],

    'rate_limit' => [
        'guest_per_minute' => (int) env('AI_RATE_LIMIT_GUEST', 20),
        'shop_per_minute' => (int) env('AI_RATE_LIMIT_SHOP', 120),
    ],

    'moderation' => [
        // Optional OpenAI free moderations endpoint when OPENAI_API_KEY is set.
        'openai_enabled' => (bool) env('AI_OPENAI_MODERATION_ENABLED', true),
    ],

    'cost' => [
        // Approximate USD per 1M tokens for audit estimates (override via env).
        'gemini_input_per_million' => (float) env('AI_COST_GEMINI_INPUT_PER_M', 0.075),
        'gemini_output_per_million' => (float) env('AI_COST_GEMINI_OUTPUT_PER_M', 0.30),
        'gemini_embedding_per_million' => (float) env('AI_COST_GEMINI_EMBED_PER_M', 0.01),
    ],

    'queue' => env('AI_QUEUE', 'ai-processing'),

];
