<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiAuditLog;
use App\Models\Shop;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AiAuditLogger
{
    public function newTraceId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(
        string $traceId,
        string $operation,
        ?Shop $shop = null,
        ?string $provider = null,
        ?string $model = null,
        int $inputTokens = 0,
        int $outputTokens = 0,
        ?int $latencyMs = null,
        string $status = 'ok',
        array $meta = [],
    ): ?AiAuditLog {
        try {
            if (! Schema::hasTable('ai_audit_logs')) {
                return null;
            }

            $total = max(0, $inputTokens) + max(0, $outputTokens);

            return AiAuditLog::query()->create([
                'trace_id' => $traceId,
                'shop_id' => $shop?->id,
                'operation' => $operation,
                'provider' => $provider,
                'model' => $model,
                'input_tokens' => max(0, $inputTokens),
                'output_tokens' => max(0, $outputTokens),
                'total_tokens' => $total,
                'estimated_cost_usd' => $this->estimateCost($operation, $inputTokens, $outputTokens),
                'latency_ms' => $latencyMs,
                'status' => $status,
                'meta' => $meta === [] ? null : $meta,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    private function estimateCost(string $operation, int $inputTokens, int $outputTokens): float
    {
        if (str_contains($operation, 'embed')) {
            $rate = (float) config('ai.cost.gemini_embedding_per_million', 0.01);

            return round(($inputTokens / 1_000_000) * $rate, 8);
        }

        $inputRate = (float) config('ai.cost.gemini_input_per_million', 0.075);
        $outputRate = (float) config('ai.cost.gemini_output_per_million', 0.30);

        return round(
            ($inputTokens / 1_000_000) * $inputRate + ($outputTokens / 1_000_000) * $outputRate,
            8,
        );
    }
}
