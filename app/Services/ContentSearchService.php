<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContentChunk;
use App\Models\Shop;
use App\Support\VectorStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContentSearchService
{
    /**
     * @var list<list<string>>
     */
    private const SYNONYM_GROUPS = [
        ['return', 'returns', 'returning', 'refund', 'refunds', 'refunded', 'exchange', 'exchanges'],
        ['shipping', 'delivery', 'ship', 'transit'],
        ['privacy', 'gdpr', 'personal'],
    ];

    public function __construct(
        private readonly ContentEmbeddingService $embeddingService,
    ) {}

    /**
     * Hybrid search over synced policies / pages / articles.
     *
     * @param  list<string>|null  $sourceTypes
     * @return Collection<int, ContentChunk>
     */
    public function search(
        string $query,
        int $limit = 5,
        ?Shop $shop = null,
        ?array $sourceTypes = null,
    ): Collection {
        $limit = max(1, min($limit, 8));
        $candidateLimit = max($limit * 6, 24);
        $intent = $this->detectIntent($query);

        if ($sourceTypes === null && $intent !== null) {
            $sourceTypes = ['policy'];
        }

        // Focused keyword path first for return/refund — never let Privacy flood the top-N.
        if ($intent === 'return') {
            $focused = $this->focusedPolicySearch(
                requiredTerms: ['return', 'returns', 'returning', 'refund', 'refunds', 'refunded', 'exchange'],
                shop: $shop,
                limit: $limit,
            );

            if ($focused->isNotEmpty()) {
                return $focused;
            }
        }

        if ($intent === 'shipping') {
            $focused = $this->focusedPolicySearch(
                requiredTerms: ['shipping', 'delivery', 'ship', 'transit', 'freight'],
                shop: $shop,
                limit: $limit,
            );

            if ($focused->isNotEmpty()) {
                return $focused;
            }
        }

        if ($intent === 'privacy') {
            $focused = $this->focusedPolicySearch(
                requiredTerms: ['privacy', 'gdpr', 'personal data', 'cookie'],
                shop: $shop,
                limit: $limit,
            );

            if ($focused->isNotEmpty()) {
                return $focused;
            }
        }

        // Keyword-first: strong title matches skip the Gemini embedding + PHP cosine scan.
        $keywordHits = $this->keywordSearch($query, $candidateLimit, $shop, $sourceTypes, $intent);

        if ($this->hasStrongKeywordHits($keywordHits, $limit)) {
            return $this->dedupeBySourceKey($keywordHits, $limit);
        }

        $expandedQuery = $this->expandQuery($query);
        $vectorHits = collect();

        try {
            $queryVector = $this->embeddingService->getEmbedding($expandedQuery);
            $vectorHits = $this->searchWithPhpCosine($queryVector, $candidateLimit, $shop, $sourceTypes, $intent);
        } catch (\Throwable) {
            //
        }

        return $this->mergeHybridResults($vectorHits, $keywordHits, $limit, $intent);
    }

    /**
     * @param  Collection<int, ContentChunk>  $keywordHits
     */
    private function hasStrongKeywordHits(Collection $keywordHits, int $limit): bool
    {
        if ($keywordHits->isEmpty()) {
            return false;
        }

        $strong = $keywordHits
            ->filter(static fn (ContentChunk $chunk): bool => (float) $chunk->getAttribute('distance') <= 0.12)
            ->values();

        return $strong->count() >= min(2, $limit);
    }

    public function looksLikePolicyQuestion(string $query): bool
    {
        return $this->detectIntent($query) !== null
            || (bool) preg_match('/\b(policy|policies|warranty|terms)\b/i', $query);
    }

    /**
     * @return 'return'|'shipping'|'privacy'|null
     */
    public function detectIntent(string $query): ?string
    {
        $q = mb_strtolower($query);

        if (preg_match('/\b(return|returns|returning|refund|refunds|exchange|exchanges)\b/i', $q)) {
            return 'return';
        }

        if (preg_match('/\b(shipping|delivery|ship|transit)\b/i', $q)) {
            return 'shipping';
        }

        if (preg_match('/\b(privacy|gdpr|personal data|cookie)\b/i', $q)) {
            return 'privacy';
        }

        return null;
    }

    /**
     * @param  list<string>  $requiredTerms
     * @return Collection<int, ContentChunk>
     */
    private function focusedPolicySearch(array $requiredTerms, ?Shop $shop, int $limit): Collection
    {
        $builder = ContentChunk::query()->where('source_type', 'policy');

        if ($shop !== null) {
            $builder->where('shop_id', $shop->id);
        }

        $builder->where(function (Builder $outer) use ($requiredTerms): void {
            foreach ($requiredTerms as $term) {
                $outer->orWhere('title', 'like', '%'.$term.'%')
                    ->orWhere('body', 'like', '%'.$term.'%');
            }
        });

        $chunks = $builder->limit(50)->get();

        if ($chunks->isEmpty()) {
            return collect();
        }

        $scored = $chunks->map(function (ContentChunk $chunk) use ($requiredTerms): ContentChunk {
            $title = mb_strtolower((string) $chunk->title);
            $body = mb_strtolower((string) $chunk->body);
            $score = 1.0;

            foreach ($requiredTerms as $term) {
                $term = mb_strtolower($term);

                if (str_contains($title, $term)) {
                    $score -= 0.35;
                }

                if (str_contains($body, $term)) {
                    $score -= 0.15;
                }
            }

            // Prefer title "Refund policy" strongly over long Privacy bodies that mention "return" once.
            if (preg_match('/\b(refund|return)\b/i', (string) $chunk->title)) {
                $score -= 0.4;
            }

            $chunk->setAttribute('distance', max(0.01, $score));

            return $chunk;
        });

        return $this->dedupeBySourceKey(
            $scored->sortBy(static fn (ContentChunk $c): float => (float) $c->getAttribute('distance'))->values(),
            $limit,
        );
    }

    /**
     * @param  array<int, float>  $queryVector
     * @param  list<string>|null  $sourceTypes
     * @return Collection<int, ContentChunk>
     */
    private function searchWithPhpCosine(
        array $queryVector,
        int $limit,
        ?Shop $shop,
        ?array $sourceTypes,
        ?string $intent,
    ): Collection {
        $builder = ContentChunk::query()->whereNotNull('embedding');

        if ($shop !== null) {
            $builder->where('shop_id', $shop->id);
        }

        if ($sourceTypes !== null && $sourceTypes !== []) {
            $builder->whereIn('source_type', $sourceTypes);
        }

        if (VectorStorage::usesNativeVector()) {
            $builder->select('*')->selectRaw('VECTOR_TO_STRING(embedding) as embedding_string');
        }

        // Cap PHP cosine candidates — full-table scans are the main content-search latency.
        $scanLimit = (int) config('ai.rag.content_vector_scan_limit', 150);
        $chunks = $builder->limit(max(50, min($scanLimit, 300)))->get();
        $scored = [];

        foreach ($chunks as $chunk) {
            $embedding = $this->decodeEmbedding(
                $chunk->getAttribute('embedding_string')
                    ?? $chunk->getAttributes()['embedding']
                    ?? null,
                (int) $chunk->id,
            );

            if ($embedding === null) {
                continue;
            }

            $distance = $this->cosineDistance($queryVector, $embedding);
            $distance = $this->applyIntentBias($chunk, $distance, $intent);
            $chunk->setAttribute('distance', $distance);
            $scored[] = $chunk;
        }

        usort($scored, static fn (ContentChunk $a, ContentChunk $b): int => ($a->getAttribute('distance') <=> $b->getAttribute('distance')));

        return collect(array_slice($scored, 0, $limit));
    }

    /**
     * @param  list<string>|null  $sourceTypes
     * @return Collection<int, ContentChunk>
     */
    private function keywordSearch(
        string $query,
        int $limit,
        ?Shop $shop,
        ?array $sourceTypes,
        ?string $intent,
    ): Collection {
        $tokens = $this->searchTokens($query);
        $tokens = array_values(array_unique([...$tokens, ...$this->synonymExpansion($tokens)]));

        // Drop ultra-generic tokens that match every policy title ("… policy").
        $tokens = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => ! in_array($token, ['policy', 'policies', 'terms', 'tos'], true),
        ));

        if ($tokens === []) {
            return collect();
        }

        $builder = ContentChunk::query();

        if ($shop !== null) {
            $builder->where('shop_id', $shop->id);
        }

        if ($sourceTypes !== null && $sourceTypes !== []) {
            $builder->whereIn('source_type', $sourceTypes);
        }

        $builder->where(function ($outer) use ($tokens): void {
            foreach ($tokens as $token) {
                $outer->orWhere('title', 'like', '%'.$token.'%')
                    ->orWhere('body', 'like', '%'.$token.'%');
            }
        });

        return $builder
            ->limit(max($limit * 8, 40))
            ->get()
            ->map(function (ContentChunk $chunk) use ($tokens, $intent, $query): ContentChunk {
                $distance = $this->keywordRelevanceDistance($chunk, $tokens, $query);
                $distance = $this->applyIntentBias($chunk, $distance, $intent);
                $chunk->setAttribute('distance', $distance);

                return $chunk;
            })
            ->sortBy(static fn (ContentChunk $chunk): float => (float) $chunk->getAttribute('distance'))
            ->values()
            ->take($limit * 3);
    }

    /**
     * Lower distance = better match. Title token overlap beats body-only hits.
     *
     * @param  list<string>  $tokens
     */
    private function keywordRelevanceDistance(ContentChunk $chunk, array $tokens, string $query): float
    {
        $title = mb_strtolower((string) $chunk->title);
        $body = mb_strtolower((string) $chunk->body);
        $queryLower = mb_strtolower(trim($query));

        if ($queryLower !== '' && str_contains($title, $queryLower)) {
            return 0.02;
        }

        $titleHits = 0;
        $bodyHits = 0;

        foreach ($tokens as $token) {
            if (str_contains($title, $token)) {
                $titleHits++;
            } elseif (str_contains($body, $token)) {
                $bodyHits++;
            }
        }

        $tokenCount = max(1, count($tokens));
        $titleRatio = $titleHits / $tokenCount;

        // Strong title overlap (e.g. "best" + "product" + "hair" in title).
        if ($titleHits >= 2) {
            return max(0.04, 0.22 - ($titleRatio * 0.18));
        }

        if ($titleHits === 1) {
            return max(0.12, 0.28 - ($bodyHits * 0.02));
        }

        // Body-only matches are weak — keep them below title hits.
        return max(0.25, 0.42 - ($bodyHits * 0.02));
    }

    /**
     * @param  Collection<int, ContentChunk>  $vectorHits
     * @param  Collection<int, ContentChunk>  $keywordHits
     * @return Collection<int, ContentChunk>
     */
    private function mergeHybridResults(
        Collection $vectorHits,
        Collection $keywordHits,
        int $limit,
        ?string $intent,
    ): Collection {
        $maxDistance = (float) config('ai.rag.max_cosine_distance', 0.55);
        /** @var array<int, ContentChunk> $byId */
        $byId = [];

        foreach ($vectorHits as $chunk) {
            $distance = $chunk->getAttribute('distance');

            if (! is_numeric($distance) || (float) $distance > $maxDistance) {
                continue;
            }

            $byId[(int) $chunk->id] = $chunk;
        }

        foreach ($keywordHits as $chunk) {
            $id = (int) $chunk->id;

            if (! isset($byId[$id]) || (float) $chunk->getAttribute('distance') < (float) $byId[$id]->getAttribute('distance')) {
                $byId[$id] = $chunk;
            }
        }

        $merged = collect(array_values($byId))
            ->sortBy(static fn (ContentChunk $chunk): float => (float) $chunk->getAttribute('distance'))
            ->values();

        return $this->dedupeBySourceKey($merged, $limit);
    }

    /**
     * One chunk per policy/page/article so Privacy's many chunks can't fill the window.
     *
     * @param  Collection<int, ContentChunk>  $chunks
     * @return Collection<int, ContentChunk>
     */
    private function dedupeBySourceKey(Collection $chunks, int $limit): Collection
    {
        $seen = [];
        $out = [];

        foreach ($chunks as $chunk) {
            $key = $chunk->source_type.'|'.$chunk->source_key;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $chunk;

            if (count($out) >= $limit) {
                break;
            }
        }

        return collect($out);
    }

    private function applyIntentBias(ContentChunk $chunk, float $distance, ?string $intent): float
    {
        if ($intent === null) {
            return $distance;
        }

        $title = mb_strtolower((string) $chunk->title);
        $body = mb_strtolower((string) $chunk->body);

        $needles = match ($intent) {
            'return' => ['refund', 'return', 'exchange'],
            'shipping' => ['shipping', 'delivery'],
            'privacy' => ['privacy'],
            default => [],
        };

        foreach ($needles as $needle) {
            if (str_contains($title, $needle)) {
                return max(0.01, $distance - 0.35);
            }

            if (str_contains($body, $needle)) {
                $distance = max(0.01, $distance - 0.1);
            }
        }

        // Penalize wrong policy when intent is clear (Privacy vs Refund).
        if ($intent === 'return' && str_contains($title, 'privacy') && ! str_contains($title, 'refund') && ! str_contains($title, 'return')) {
            $distance += 0.35;
        }

        return $distance;
    }

    private function expandQuery(string $query): string
    {
        $tokens = $this->searchTokens($query);
        $extra = $this->synonymExpansion($tokens);

        if ($extra === []) {
            return $query;
        }

        return trim($query.' '.implode(' ', $extra));
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function synonymExpansion(array $tokens): array
    {
        $extra = [];

        foreach (self::SYNONYM_GROUPS as $group) {
            $matched = false;

            foreach ($tokens as $token) {
                foreach ($group as $term) {
                    if ($token === $term || str_starts_with($token, $term) || str_starts_with($term, $token)) {
                        $matched = true;
                        break 2;
                    }
                }
            }

            if (! $matched) {
                continue;
            }

            foreach ($group as $term) {
                if (! in_array($term, $tokens, true)) {
                    $extra[] = $term;
                }
            }
        }

        return array_values(array_unique($extra));
    }

    /**
     * @return list<string>
     */
    private function searchTokens(string $query): array
    {
        $parts = preg_split('/[^a-z0-9]+/i', mb_strtolower(trim($query))) ?: [];
        // Keep "product/products" — content titles like "Best Products for Hair…" need them.
        $stop = [
            'a', 'an', 'the', 'i', 'im', 'am', 'to', 'of', 'in', 'and', 'or', 'with',
            'my', 'our', 'this', 'that', 'have', 'has', 'had', 'can', 'could', 'please',
            'before', 'after', 'days', 'day', 'ago', 'bought', 'purchase', 'purchased',
            'shirt', 'item', 'items', 'now', 'specific', 'window', 'about', 'want', 'know',
        ];

        return array_values(array_filter(
            $parts,
            static fn (string $token): bool => mb_strlen($token) >= 3 && ! in_array($token, $stop, true),
        ));
    }

    /**
     * @return array<int, float>|null
     */
    private function decodeEmbedding(mixed $raw, int $chunkId = 0): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (is_array($raw)) {
            return array_map(static fn ($v): float => (float) $v, $raw);
        }

        if (is_string($raw) && str_starts_with(ltrim($raw), '[')) {
            return $this->parseVectorLiteral($raw);
        }

        if ($chunkId > 0 && VectorStorage::usesNativeVector()) {
            try {
                $row = DB::selectOne(
                    'SELECT VECTOR_TO_STRING(embedding) AS embedding_string FROM content_chunks WHERE id = ?',
                    [$chunkId],
                );
                $literal = is_object($row) ? (string) ($row->embedding_string ?? '') : '';

                return $literal !== '' ? $this->parseVectorLiteral($literal) : null;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array<int, float>|null
     */
    private function parseVectorLiteral(string $value): ?array
    {
        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            return array_map(static fn ($v): float => (float) $v, $decoded);
        }

        if (! preg_match('/^\s*\[(.*)\]\s*$/s', $value, $matches)) {
            return null;
        }

        $parts = trim($matches[1]) === ''
            ? []
            : (preg_split('/\s*,\s*/', trim($matches[1])) ?: []);

        $vector = [];

        foreach ($parts as $part) {
            if (! is_numeric($part)) {
                return null;
            }

            $vector[] = (float) $part;
        }

        return $vector;
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosineDistance(array $a, array $b): float
    {
        $n = min(count($a), count($b));

        if ($n === 0) {
            return 1.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 1.0;
        }

        $similarity = $dot / (sqrt($normA) * sqrt($normB));

        return 1.0 - max(-1.0, min(1.0, $similarity));
    }
}
