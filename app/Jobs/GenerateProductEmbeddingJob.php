<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use App\Services\ProductEmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateProductEmbeddingJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Product $product,
    ) {
        $this->onQueue((string) config('ai.queue', 'ai-processing'));
    }

    /**
     * Exponential backoff (seconds) for rate-limit and transient failures.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    /**
     * Execute the job.
     */
    public function handle(ProductEmbeddingService $service): void
    {
        $service->generateAndSave($this->product);
    }
}
