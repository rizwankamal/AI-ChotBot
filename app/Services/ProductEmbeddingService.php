<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Support\VectorStorage;
use Illuminate\Support\Facades\Cache;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use RuntimeException;

class ProductEmbeddingService
{
    /**
     * Generate a 768-dimensional embedding vector for the given text.
     *
     * @return array<int, float>
     */
    public function getEmbedding(string $text): array
    {
        $normalized = trim($text);
        $ttl = max(60, (int) config('ai.embedding_cache.ttl_seconds', 3_600));
        $cacheKey = 'embed:v1:'.hash('sha256', mb_strtolower($normalized));

        /** @var array<int, float>|null $cached */
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $response = Prism::embeddings()
            ->using(Provider::Gemini, 'gemini-embedding-001')
            ->fromInput($normalized)
            ->withProviderOptions(['outputDimensionality' => 768])
            ->asEmbeddings();

        $embedding = $response->embeddings[0]->embedding ?? null;

        if (! is_array($embedding) || $embedding === []) {
            throw new RuntimeException('Prism returned an empty embedding vector.');
        }

        /** @var array<int, float> $embedding */
        $embedding = array_map(static fn (int|float|string $value): float => (float) $value, $embedding);

        Cache::put($cacheKey, $embedding, $ttl);

        return $embedding;
    }

    /**
     * Persist a precomputed embedding (JSON or native VECTOR via STRING_TO_VECTOR).
     *
     * @param  array<int, float>  $vector
     */
    public function saveEmbedding(Product $product, array $vector): void
    {
        VectorStorage::saveProductEmbedding((int) $product->id, $vector);

        // Avoid Eloquent refresh() reloading a raw VECTOR blob into the model.
        $product->syncOriginal();
    }

    /**
     * Build contextual product text, generate an embedding, and persist it.
     */
    public function generateAndSave(Product $product): void
    {
        $text = sprintf(
            'Title: %s. Category: %s. Description: %s. Price: %s',
            $product->title,
            $product->category ?? '',
            $product->description ?? '',
            (string) $product->price,
        );

        $this->saveEmbedding($product, $this->getEmbedding($text));
    }
}
