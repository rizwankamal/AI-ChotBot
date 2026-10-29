<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\Shop;
use App\Support\VectorStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SemanticSearchService
{
    /**
     * Apparel synonym groups — "woolen clothes" should surface sweatshirts/knits/hoodies.
     *
     * @var list<list<string>>
     */
    private const SYNONYM_GROUPS = [
        ['wool', 'woolen', 'woollen', 'sweater', 'jumper', 'knit', 'knitted', 'fleece', 'sweatshirt', 'hoodie', 'cardigan', 'pullover', 'jacket', 'coat', 'warm'],
        ['shirt', 'tshirt', 'tee', 'polo', 'blouse', 'top'],
        ['jean', 'jeans', 'denim', 'pants', 'trousers', 'jogger', 'sweatpants'],
        ['short', 'shorts', 'bermuda', 'swim'],
        ['bag', 'backpack', 'crossbody'],
        ['belt'],
    ];

    public function __construct(
        private readonly ProductEmbeddingService $embeddingService,
    ) {}

    /**
     * Hybrid catalog search: vector similarity + title/description keyword hits.
     *
     * Gemini embeddings often land true matches around cosine distance 0.35–0.50,
     * so the OpenAI-oriented 0.28 cut-off is too aggressive for this stack.
     *
     * @return Collection<int, Product>
     */
    public function search(
        string $query,
        ?float $maxPrice = null,
        ?string $category = null,
        int $limit = 5,
        ?Shop $shop = null,
    ): Collection {
        $limit = max(1, min($limit, (int) config('ai.chat.top_k_products', 5)));
        $candidateLimit = max($limit * 4, 20);

        $expandedQuery = $this->expandQueryForEmbedding($query);
        $queryVector = $this->embeddingService->getEmbedding($expandedQuery);

        $vectorHits = VectorStorage::usesNativeVector() && VectorStorage::supportsDistanceFunction()
            ? $this->searchWithSqlDistance($queryVector, $maxPrice, $category, $candidateLimit, $shop)
            : $this->searchWithPhpCosine($queryVector, $maxPrice, $category, $candidateLimit, $shop);

        $keywordHits = $this->keywordSearch($query, $maxPrice, $category, $candidateLimit, $shop);

        return $this->mergeHybridResults($vectorHits, $keywordHits, $limit);
    }

    /**
     * Expand short intent queries with apparel synonyms for better embeddings.
     */
    public function expandQueryForEmbedding(string $query): string
    {
        $tokens = $this->searchTokens($query);
        $extra = $this->synonymExpansion($tokens);

        if ($extra === []) {
            return $query;
        }

        return trim($query.' '.implode(' ', $extra));
    }

    /**
     * @param  Collection<int, Product>  $vectorHits
     * @param  Collection<int, Product>  $keywordHits
     * @return Collection<int, Product>
     */
    private function mergeHybridResults(Collection $vectorHits, Collection $keywordHits, int $limit): Collection
    {
        $maxDistance = (float) config('ai.rag.max_cosine_distance', 0.55);
        /** @var array<int, Product> $byId */
        $byId = [];

        foreach ($vectorHits as $product) {
            $distance = $product->getAttribute('distance');

            if (! is_numeric($distance) || (float) $distance > $maxDistance) {
                continue;
            }

            $byId[(int) $product->id] = $product;
        }

        // Keyword / entity hits always win for exact title matches (sweatshirt, hoodie…).
        foreach ($keywordHits as $product) {
            $id = (int) $product->id;

            if (isset($byId[$id])) {
                $product->setAttribute('distance', min(
                    (float) $byId[$id]->getAttribute('distance'),
                    (float) ($product->getAttribute('distance') ?? 0.05),
                ));
                $product->setAttribute('match_type', 'hybrid');
                $byId[$id] = $product;

                continue;
            }

            if (! is_numeric($product->getAttribute('distance'))) {
                $product->setAttribute('distance', 0.05);
            }

            $product->setAttribute('match_type', 'keyword');
            $byId[$id] = $product;
        }

        return collect(array_values($byId))
            ->sortBy(static fn (Product $product): float => (float) $product->getAttribute('distance'))
            ->values()
            ->take($limit);
    }

    /**
     * Full-text-ish keyword pass for product names (hybrid search).
     *
     * @return Collection<int, Product>
     */
    private function keywordSearch(
        string $query,
        ?float $maxPrice,
        ?string $category,
        int $limit,
        ?Shop $shop,
    ): Collection {
        $tokens = $this->searchTokens($query);
        $tokens = array_values(array_unique([...$tokens, ...$this->synonymExpansion($tokens)]));

        if ($tokens === []) {
            return collect();
        }

        $builder = $this->baseQuery($maxPrice, $category, $shop)
            ->select([
                'id',
                'shop_id',
                'shopify_id',
                'handle',
                'title',
                'description',
                'price',
                'category',
                'image_url',
                'inventory_count',
            ])
            ->where(function (Builder $outer) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%'.$token.'%';
                    $outer->orWhere('title', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('category', 'like', $like);
                }
            })
            ->limit($limit);

        return $builder->get()->map(static function (Product $product) use ($tokens): Product {
            $haystack = mb_strtolower($product->title.' '.($product->description ?? '').' '.($product->category ?? ''));
            $hits = 0;

            foreach ($tokens as $token) {
                if (str_contains($haystack, $token)) {
                    $hits++;
                }
            }

            // Stronger title hits sort ahead of weak vector-only matches.
            $product->setAttribute('distance', max(0.01, 0.08 - (0.01 * $hits)));

            return $product;
        });
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
        $normalized = mb_strtolower(trim($query));
        $parts = preg_split('/[^a-z0-9]+/i', $normalized) ?: [];

        $stop = [
            'a', 'an', 'the', 'any', 'some', 'okay', 'ok', 'please', 'want', 'need',
            'looking', 'for', 'show', 'me', 'find', 'i', 'im', 'am', 'to', 'of', 'in',
            'and', 'or', 'with', 'under', 'over', 'my', 'our', 'this', 'that', 'clothes',
            'clothing', 'wear', 'item', 'items', 'product', 'products',
        ];

        $tokens = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if (mb_strlen($part) < 3 || in_array($part, $stop, true)) {
                continue;
            }

            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  array<int, float>  $vector
     */
    public function formatVectorString(array $vector): string
    {
        return VectorStorage::toVectorString($vector);
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float|int|string>  $b
     */
    public function cosineDistance(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            throw new InvalidArgumentException('Embedding dimensions must match for cosine distance.');
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $index => $valueA) {
            $valueB = (float) $b[$index];
            $valueA = (float) $valueA;

            $dot += $valueA * $valueB;
            $normA += $valueA * $valueA;
            $normB += $valueB * $valueB;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 1.0;
        }

        $similarity = $dot / (sqrt($normA) * sqrt($normB));

        return 1.0 - max(-1.0, min(1.0, $similarity));
    }

    /**
     * @param  array<int, float>  $queryVector
     * @return Collection<int, Product>
     */
    private function searchWithSqlDistance(
        array $queryVector,
        ?float $maxPrice,
        ?string $category,
        int $limit,
        ?Shop $shop,
    ): Collection {
        $vectorLiteral = $this->formatVectorString($queryVector);

        return $this->baseQuery($maxPrice, $category, $shop)
            ->select([
                'id',
                'shop_id',
                'shopify_id',
                'handle',
                'title',
                'description',
                'price',
                'category',
                'image_url',
                'inventory_count',
            ])
            ->selectRaw(
                "VECTOR_DISTANCE(embedding, STRING_TO_VECTOR(?), 'COSINE') as distance",
                [$vectorLiteral],
            )
            ->orderBy('distance')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<int, float>  $queryVector
     * @return Collection<int, Product>
     */
    private function searchWithPhpCosine(
        array $queryVector,
        ?float $maxPrice,
        ?string $category,
        int $limit,
        ?Shop $shop,
    ): Collection {
        $query = $this->baseQuery($maxPrice, $category, $shop)->select([
            'id',
            'shop_id',
            'shopify_id',
            'handle',
            'title',
            'description',
            'price',
            'category',
            'image_url',
            'inventory_count',
        ]);

        if (VectorStorage::usesNativeVector()) {
            $query->selectRaw('VECTOR_TO_STRING(embedding) as embedding_string');
        } else {
            $query->addSelect('embedding');
        }

        $products = $query->get();

        return $products
            ->map(function (Product $product) use ($queryVector): ?Product {
                $embedding = $this->resolveEmbeddingArray($product);

                if ($embedding === null || $embedding === []) {
                    return null;
                }

                $product->setAttribute(
                    'distance',
                    $this->cosineDistance($queryVector, $embedding),
                );
                $product->setAttribute('match_type', 'vector');

                return $product;
            })
            ->filter()
            ->sortBy(static fn (Product $product): float => (float) $product->getAttribute('distance'))
            ->values()
            ->take($limit);
    }

    /**
     * @return array<int, float>|null
     */
    private function resolveEmbeddingArray(Product $product): ?array
    {
        $fromString = $product->getAttribute('embedding_string');

        if (is_string($fromString) && $fromString !== '') {
            $decoded = json_decode($fromString, true);

            if (is_array($decoded)) {
                /** @var array<int, float|int|string> $decoded */
                return array_map(static fn (float|int|string $item): float => (float) $item, $decoded);
            }
        }

        $embedding = $product->embedding;

        return is_array($embedding) ? $embedding : null;
    }

    /**
     * @return Builder<Product>
     */
    private function baseQuery(?float $maxPrice, ?string $category, ?Shop $shop = null): Builder
    {
        $query = Product::query()
            ->whereNotNull('embedding')
            ->where('inventory_count', '>', 0);

        if ($shop !== null) {
            $query->where('shop_id', $shop->id);
        }

        if ($maxPrice !== null) {
            $query->where('price', '<=', $maxPrice);
        }

        if ($category !== null && $category !== '') {
            $query->where('category', $category);
        }

        return $query;
    }
}
