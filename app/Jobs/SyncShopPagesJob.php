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

class SyncShopPagesJob implements ShouldQueue
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
            $cursor = null;
            $liveKeys = [];
            $chunkCount = 0;
            $documentCount = 0;

            do {
                $result = $client->fetchPages(50, $cursor);

                foreach ($result['pages'] as $page) {
                    if (! (bool) ($page['isPublished'] ?? false)) {
                        continue;
                    }

                    $sourceKey = (string) ($page['id'] ?? '');
                    $title = (string) ($page['title'] ?? '');
                    $handle = (string) ($page['handle'] ?? '');
                    $body = (string) ($page['body'] ?? '');

                    if ($sourceKey === '' || $body === '') {
                        continue;
                    }

                    $liveKeys[] = $sourceKey;
                    $url = $handle !== ''
                        ? 'https://'.$this->shop->shop_domain.'/pages/'.$handle
                        : null;

                    $upserted = $contentSync->upsertDocument(
                        shop: $this->shop,
                        sourceType: ShopifyContentSyncService::SOURCE_PAGE,
                        sourceKey: $sourceKey,
                        title: $title !== '' ? $title : $handle,
                        bodyHtml: $body,
                        url: $url,
                    );
                    $chunkCount += $upserted['chunks'];
                    $documentCount++;
                }

                $cursor = $result['pageInfo']['hasNextPage'] ? $result['pageInfo']['endCursor'] : null;
            } while ($cursor !== null);

            $pruned = $contentSync->pruneMissing(
                $this->shop,
                ShopifyContentSyncService::SOURCE_PAGE,
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
