<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ContentChunk;
use App\Services\ContentEmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateContentChunkEmbeddingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public ContentChunk $chunk,
    ) {
        $this->onQueue((string) config('ai.queue', 'ai-processing'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(ContentEmbeddingService $service): void
    {
        $service->generateAndSave($this->chunk);
    }
}
