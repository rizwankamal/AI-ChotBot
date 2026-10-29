<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContentChunk;
use App\Support\VectorStorage;

class ContentEmbeddingService
{
    public function __construct(
        private readonly ProductEmbeddingService $productEmbeddings,
    ) {}

    /**
     * @return array<int, float>
     */
    public function getEmbedding(string $text): array
    {
        return $this->productEmbeddings->getEmbedding($text);
    }

    /**
     * @param  array<int, float>  $vector
     */
    public function saveEmbedding(ContentChunk $chunk, array $vector): void
    {
        VectorStorage::saveContentChunkEmbedding((int) $chunk->id, $vector);
        $chunk->syncOriginal();
    }

    public function generateAndSave(ContentChunk $chunk): void
    {
        $text = sprintf(
            'Type: %s. Title: %s. Content: %s',
            $chunk->source_type,
            $chunk->title,
            $chunk->body,
        );

        $this->saveEmbedding($chunk, $this->getEmbedding($text));
    }
}
