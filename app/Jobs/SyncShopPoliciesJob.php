<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncRun;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Services\ShopifyClientService;
use App\Services\ShopifyContentSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncShopPoliciesJob implements ShouldQueue
{
    use Queueable;
    use TracksSyncRun;

    public int $tries = 3;

    public function __construct(
        public Shop $shop,
        public SyncRun $syncRun,
    ) {
        $this->onQueue((string) config('ai.queue', 'ai-processing'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        ShopifyClientService $shopify,
        ShopifyContentSyncService $contentSync,
    ): void {
        if (! $this->shop->isInstalled()) {
            $this->syncRun->markFailed('Shop is not installed.');

            return;
        }

        $this->withSyncRun($this->syncRun, function () use ($shopify, $contentSync): array {
            $client = $shopify->forShop($this->shop);
            $policies = $client->fetchShopPolicies();
            $liveKeys = [];
            $chunkCount = 0;
            $documentCount = 0;

            foreach ($policies as $policy) {
                $type = (string) ($policy['type'] ?? '');
                $title = (string) ($policy['title'] ?? $type);
                $body = (string) ($policy['body'] ?? '');
                $url = isset($policy['url']) ? (string) $policy['url'] : null;
                $sourceKey = (string) ($policy['id'] ?? $type);

                if ($sourceKey === '' || $body === '') {
                    continue;
                }

                $liveKeys[] = $sourceKey;
                $result = $contentSync->upsertDocument(
                    shop: $this->shop,
                    sourceType: ShopifyContentSyncService::SOURCE_POLICY,
                    sourceKey: $sourceKey,
                    title: $title !== '' ? $title : $type,
                    bodyHtml: $body,
                    url: $url !== '' ? $url : null,
                );
                $chunkCount += $result['chunks'];
                $documentCount++;
            }

            $pruned = $contentSync->pruneMissing(
                $this->shop,
                ShopifyContentSyncService::SOURCE_POLICY,
                $liveKeys,
            );

            return [
                'documents' => $documentCount,
                'chunks' => $chunkCount,
                'pruned' => $pruned,
            ];
        });
    }
}
