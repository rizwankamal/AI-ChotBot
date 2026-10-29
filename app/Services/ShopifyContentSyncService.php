<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateContentChunkEmbeddingJob;
use App\Models\ContentChunk;
use App\Models\Shop;
use App\Support\ContentChunker;
use Illuminate\Support\Facades\DB;

class ShopifyContentSyncService
{
    public const SOURCE_POLICY = 'policy';

    public const SOURCE_PAGE = 'page';

    public const SOURCE_ARTICLE = 'article';

    /**
     * Replace all chunks for a source document and queue embeddings.
     *
     * @return array{chunks: int, source_key: string}
     */
    public function upsertDocument(
        Shop $shop,
        string $sourceType,
        string $sourceKey,
        string $title,
        string $bodyHtml,
        ?string $url = null,
        bool $dispatchEmbedding = true,
    ): array {
        $chunks = ContentChunker::chunk($bodyHtml);

        if ($chunks === []) {
            ContentChunk::query()
                ->where('shop_id', $shop->id)
                ->where('source_type', $sourceType)
                ->where('source_key', $sourceKey)
                ->delete();

            return ['chunks' => 0, 'source_key' => $sourceKey];
        }

        $keptIds = [];

        DB::transaction(function () use ($shop, $sourceType, $sourceKey, $title, $url, $chunks, &$keptIds, $dispatchEmbedding): void {
            foreach ($chunks as $index => $body) {
                $chunk = ContentChunk::query()->updateOrCreate(
                    [
                        'shop_id' => $shop->id,
                        'source_type' => $sourceType,
                        'source_key' => $sourceKey,
                        'chunk_index' => $index,
                    ],
                    [
                        'title' => $title,
                        'url' => $url,
                        'body' => $body,
                    ],
                );

                $keptIds[] = (int) $chunk->id;

                if ($dispatchEmbedding) {
                    GenerateContentChunkEmbeddingJob::dispatch($chunk);
                }
            }

            ContentChunk::query()
                ->where('shop_id', $shop->id)
                ->where('source_type', $sourceType)
                ->where('source_key', $sourceKey)
                ->whereNotIn('id', $keptIds)
                ->delete();
        });

        return ['chunks' => count($keptIds), 'source_key' => $sourceKey];
    }

    /**
     * Remove local chunks whose source_key is not in the live Shopify set.
     *
     * @param  list<string>  $liveKeys
     */
    public function pruneMissing(Shop $shop, string $sourceType, array $liveKeys): int
    {
        $query = ContentChunk::query()
            ->where('shop_id', $shop->id)
            ->where('source_type', $sourceType);

        if ($liveKeys !== []) {
            $query->whereNotIn('source_key', $liveKeys);
        }

        return $query->delete();
    }
}
