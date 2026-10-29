<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Ai\AiAuditLogger;
use App\Services\Ai\SemanticChatCacheService;
use App\Services\StoreAssistantService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AiChatStreamController extends Controller
{
    private const SSE_HEADERS = [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'X-Accel-Buffering' => 'no',
        'Connection' => 'keep-alive',
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Headers' => '*',
    ];

    /**
     * Stream a shopping-assistant reply over Server-Sent Events.
     */
    public function __invoke(
        Request $request,
        StoreAssistantService $assistant,
        SemanticChatCacheService $semanticCache,
        AiAuditLogger $auditLogger,
    ): StreamedResponse {
        $this->mergePlainTextJsonBody($request);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'shop' => ['nullable', 'string', 'max:255'],
            'history' => ['nullable', 'array', 'max:12'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:4000'],
        ]);

        $message = trim($validated['message']);
        $shop = $this->resolveShop($validated['shop'] ?? $request->header('X-Shopify-Shop-Domain'));
        /** @var list<array{role: string, content: string}> $history */
        $history = array_values($validated['history'] ?? []);
        $traceId = $auditLogger->newTraceId();
        $cacheKey = $this->cacheKey($message, $shop);
        $ttl = max(60, (int) config('ai.exact_cache.ttl_seconds', 86_400));

        $assistant = $assistant->forShop($shop);

        return response()->stream(function () use (
            $assistant,
            $message,
            $history,
            $cacheKey,
            $ttl,
            $shop,
            $semanticCache,
            $traceId,
        ): void {
            $this->disableOutputBuffering();

            $this->writeSseEvent([
                'type' => 'meta',
                'trace_id' => $traceId,
            ]);

            // Never serve stale answers for policies, logistics, or order status.
            $skipChatCache = (bool) preg_match(
                '/\b(return|returns|refund|refunds|exchange|shipping|delivery|privacy|policy|policies|order|orders|tracking|shipment|fulfillment)\b/i',
                $message,
            );

            $cached = $skipChatCache ? null : $this->cache()->get($cacheKey);

            if (is_array($cached) && isset($cached['text']) && is_string($cached['text']) && $cached['text'] !== '') {
                $this->streamCachedPayload($cached, source: 'exact');

                return;
            }

            if (is_string($cached) && $cached !== '') {
                $this->streamCachedPayload(['text' => $cached, 'products' => []], source: 'exact');

                return;
            }

            $semanticHit = $skipChatCache
                ? null
                : $this->safeSemanticLookup($semanticCache, $message, $shop);

            if (is_string($semanticHit) && $semanticHit !== '') {
                $payload = ['text' => $semanticHit, 'products' => []];
                $this->cache()->put($cacheKey, $payload, $ttl);
                $this->streamCachedPayload($payload, source: 'semantic');

                return;
            }

            $fullResponse = '';
            /** @var list<array<string, mixed>> $products */
            $products = [];
            /** @var list<array<string, mixed>> $contentLinks */
            $contentLinks = [];
            $streamSucceeded = false;
            $streamErrored = false;

            try {
                foreach ($assistant->streamCustomerQuery($message, $history, $traceId) as $event) {
                    if (! is_array($event)) {
                        continue;
                    }

                    $type = $event['type'] ?? null;

                    if ($type === 'token') {
                        $content = (string) ($event['content'] ?? '');
                        $fullResponse .= $content;
                        $this->writeSseEvent([
                            'type' => 'token',
                            'content' => $content,
                            'cached' => false,
                        ]);
                    } elseif ($type === 'products') {
                        /** @var list<array<string, mixed>> $items */
                        $items = array_values(array_filter(
                            $event['items'] ?? [],
                            static fn ($item): bool => is_array($item),
                        ));
                        $products = $items;
                        $this->writeSseEvent([
                            'type' => 'products',
                            'items' => $products,
                            'cached' => false,
                        ]);
                    } elseif ($type === 'content_links') {
                        /** @var list<array<string, mixed>> $items */
                        $items = array_values(array_filter(
                            $event['items'] ?? [],
                            static fn ($item): bool => is_array($item),
                        ));
                        $contentLinks = $items;
                        $this->writeSseEvent([
                            'type' => 'content_links',
                            'items' => $contentLinks,
                            'cached' => false,
                        ]);
                    } elseif ($type === 'error') {
                        $streamErrored = true;
                        $this->writeSseEvent($event);
                    }
                }

                $streamSucceeded = ! $streamErrored;
            } catch (Throwable $exception) {
                $streamErrored = true;
                $streamSucceeded = false;

                Log::error('AI chat stream failed', [
                    'trace_id' => $traceId,
                    'message' => $exception->getMessage(),
                ]);

                $this->writeSseEvent([
                    'type' => 'error',
                    'trace_id' => $traceId,
                    'content' => 'Sorry, I could not complete that request right now.',
                ]);
            }

            $replyComplete = $streamSucceeded && $this->isCompleteAssistantReply($fullResponse);

            // Model sometimes stops mid-phrase without throwing — replace for the client.
            if ($streamSucceeded && $fullResponse !== '' && ! $replyComplete) {
                $this->writeSseEvent([
                    'type' => 'error',
                    'trace_id' => $traceId,
                    'content' => 'Sorry, the reply was cut off. Please try again.',
                ]);
            }

            // Never persist truncated / failed replies — they stick as exact-cache hits.
            if ($replyComplete && ! $skipChatCache) {
                $payload = [
                    'text' => $fullResponse,
                    'products' => $products,
                    'content_links' => $contentLinks,
                ];
                $this->cache()->put($cacheKey, $payload, $ttl);
                $this->safeSemanticStore($semanticCache, $message, $fullResponse, $shop);
            }

            $this->writeSseEvent([
                'type' => 'done',
                'cached' => false,
                'complete' => $replyComplete,
                'trace_id' => $traceId,
            ]);
        }, 200, self::SSE_HEADERS);
    }

    /**
     * Theme widget posts JSON as text/plain to avoid CORS preflight against tunnels.
     */
    private function mergePlainTextJsonBody(Request $request): void
    {
        $contentType = strtolower((string) $request->header('Content-Type', ''));

        if (! str_contains($contentType, 'text/plain')) {
            return;
        }

        $decoded = json_decode($request->getContent(), true);

        if (is_array($decoded)) {
            $request->merge($decoded);
        }
    }

    private function resolveShop(?string $shopDomain): ?Shop
    {
        if (! is_string($shopDomain) || trim($shopDomain) === '') {
            return null;
        }

        $shopDomain = strtolower(rtrim(preg_replace('#^https?://#', '', $shopDomain) ?? $shopDomain, '/'));

        return Shop::query()
            ->where('shop_domain', $shopDomain)
            ->whereNull('uninstalled_at')
            ->first();
    }

    /**
     * @param  array{
     *     text: string,
     *     products?: list<array<string, mixed>>,
     *     content_links?: list<array<string, mixed>>
     * }  $payload
     */
    private function streamCachedPayload(array $payload, string $source = 'exact'): void
    {
        $text = $payload['text'];

        foreach ($this->chunkCachedText($text) as $chunk) {
            $this->writeSseEvent([
                'type' => 'token',
                'content' => $chunk,
                'cached' => true,
                'cache_source' => $source,
            ]);
        }

        $products = $payload['products'] ?? [];

        if ($products !== []) {
            $this->writeSseEvent([
                'type' => 'products',
                'items' => $products,
                'cached' => true,
                'cache_source' => $source,
            ]);
        }

        $contentLinks = $payload['content_links'] ?? [];

        if ($contentLinks !== []) {
            $this->writeSseEvent([
                'type' => 'content_links',
                'items' => $contentLinks,
                'cached' => true,
                'cache_source' => $source,
            ]);
        }

        $this->writeSseEvent([
            'type' => 'done',
            'cached' => true,
            'cache_source' => $source,
        ]);
    }

    /**
     * @return list<string>
     */
    private function chunkCachedText(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $chunks = str_split($text, 48);

        return $chunks === [] ? [$text] : $chunks;
    }

    /**
     * Reject obviously truncated model output so it cannot poison exact/semantic cache.
     */
    private function isCompleteAssistantReply(string $text): bool
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return false;
        }

        // Mid-phrase cut-offs like "If your" / "Please provide the".
        if (preg_match('/\b(if|your|the|a|an|to|for|with|and|or|but|please|provide|when|while|because|that|this|these|those)\s*$/iu', $trimmed) === 1) {
            return false;
        }

        // Ultra-short fragments without sentence ending.
        if (mb_strlen($trimmed) < 12 && preg_match('/[.!?…]["\')\]]*$/u', $trimmed) !== 1) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeSseEvent(array $payload): void
    {
        echo 'data: '.json_encode($payload, JSON_THROW_ON_ERROR)."\n\n";

        if (! app()->runningUnitTests()) {
            if (ob_get_level() > 0) {
                @ob_flush();
            }

            @flush();
        }
    }

    private function disableOutputBuffering(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    private function cacheKey(string $message, ?Shop $shop): string
    {
        $shopPart = $shop?->shop_domain ?? 'global';

        return 'chat:sse:'.hash('sha256', $shopPart.'|'.mb_strtolower($message));
    }

    private function cache(): CacheRepository
    {
        $store = (string) config('ai.exact_cache.store', config('cache.chat_store', 'array'));

        return Cache::store($store);
    }

    private function safeSemanticLookup(SemanticChatCacheService $cache, string $message, ?Shop $shop): ?string
    {
        try {
            return $cache->lookup($message, $shop);
        } catch (Throwable $exception) {
            Log::debug('Semantic chat cache lookup skipped', ['message' => $exception->getMessage()]);

            return null;
        }
    }

    private function safeSemanticStore(
        SemanticChatCacheService $cache,
        string $message,
        string $response,
        ?Shop $shop,
    ): void {
        try {
            $cache->store($message, $response, $shop);
        } catch (Throwable $exception) {
            Log::debug('Semantic chat cache store skipped', ['message' => $exception->getMessage()]);
        }
    }
}
