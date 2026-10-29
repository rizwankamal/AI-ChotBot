<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiSemanticCache;
use App\Models\Shop;
use App\Services\ProductEmbeddingService;
use App\Services\SemanticSearchService;
use App\Support\VectorStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vector-similarity chat cache (semantic caching before LLM calls).
 */
class SemanticChatCacheService
{
    public function __construct(
        private readonly ProductEmbeddingService $embeddings,
        private readonly SemanticSearchService $distances,
    ) {}

    public function enabled(): bool
    {
        if (! (bool) config('ai.semantic_cache.enabled', true)) {
            return false;
        }

        try {
            return Schema::hasTable('ai_semantic_cache');
        } catch (\Throwable) {
            return false;
        }
    }

    public function lookup(string $query, ?Shop $shop = null): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $queryVector = $this->embeddings->getEmbedding($query);
        $maxDistance = (float) config('ai.semantic_cache.max_cosine_distance', 0.08);

        $builder = AiSemanticCache::query()
            ->where(function ($queryBuilder) use ($shop): void {
                if ($shop !== null) {
                    $queryBuilder->where('shop_id', $shop->id);
                } else {
                    $queryBuilder->whereNull('shop_id');
                }
            })
            ->where(function ($queryBuilder): void {
                $queryBuilder->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->limit(50);

        if ($this->embeddingColumnIsVector()) {
            $builder->select(['id', 'response_text'])
                ->selectRaw('VECTOR_TO_STRING(query_embedding) as query_embedding_string');
        } else {
            $builder->select(['id', 'response_text', 'query_embedding']);
        }

        $rows = $builder->get();

        $bestDistance = PHP_FLOAT_MAX;
        $bestResponse = null;

        foreach ($rows as $row) {
            $stored = $this->decodeEmbedding(
                $row->getAttribute('query_embedding_string')
                    ?? $row->getAttributes()['query_embedding']
                    ?? null
            );

            if ($stored === null) {
                continue;
            }

            try {
                $distance = $this->distances->cosineDistance($queryVector, $stored);
            } catch (\InvalidArgumentException) {
                continue;
            }

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestResponse = $row->response_text;
            }
        }

        if ($bestResponse !== null && $bestDistance <= $maxDistance) {
            return $bestResponse;
        }

        return null;
    }

    public function store(string $query, string $response, ?Shop $shop = null): void
    {
        if (! $this->enabled() || trim($response) === '') {
            return;
        }

        $queryVector = $this->embeddings->getEmbedding($query);
        $ttl = (int) config('ai.semantic_cache.ttl_seconds', 86_400);

        $row = AiSemanticCache::query()->create([
            'shop_id' => $shop?->id,
            'query_hash' => hash('sha256', mb_strtolower(trim($query))),
            'query_text' => mb_substr($query, 0, 2000),
            'response_text' => $response,
            'expires_at' => now()->addSeconds($ttl),
        ]);

        if ($this->embeddingColumnIsVector()) {
            DB::update(
                'UPDATE ai_semantic_cache SET query_embedding = STRING_TO_VECTOR(?) WHERE id = ?',
                [VectorStorage::toVectorString($queryVector), $row->id],
            );

            return;
        }

        DB::table('ai_semantic_cache')->where('id', $row->id)->update([
            'query_embedding' => json_encode($queryVector, JSON_THROW_ON_ERROR),
        ]);
    }

    private function embeddingColumnIsVector(): bool
    {
        if (! Schema::hasColumn('ai_semantic_cache', 'query_embedding')) {
            return false;
        }

        $dataType = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'ai_semantic_cache')
            ->where('COLUMN_NAME', 'query_embedding')
            ->value('DATA_TYPE');

        return strtolower((string) $dataType) === 'vector';
    }

    /**
     * @return array<int, float>|null
     */
    private function decodeEmbedding(mixed $value): ?array
    {
        if (is_array($value)) {
            return array_map(static fn ($v): float => (float) $v, $value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return null;
        }

        return array_map(static fn ($v): float => (float) $v, $decoded);
    }
}
