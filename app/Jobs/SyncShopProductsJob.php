<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncRun;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Services\ShopifyClientService;
use App\Services\ShopifyProductSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncShopProductsJob implements ShouldQueue
{
    use Queueable;
    use TracksSyncRun;

    public int $tries = 3;

    public function __construct(
        public Shop $shop,
        public ?SyncRun $syncRun = null,
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
        ShopifyProductSyncService $sync,
    ): void {
        if (! $this->shop->isInstalled()) {
            $this->syncRun?->markFailed('Shop is not installed.');

            return;
        }

        $runner = function () use ($shopify, $sync): array {
            $client = $shopify->forShop($this->shop);
            $cursor = null;
            $count = 0;

            do {
                $result = $client->fetchProducts(50, $cursor);

                foreach ($result['products'] as $productNode) {
                    $sync->upsertFromShopifyPayload($productNode, shop: $this->shop);
                    $count++;
                }

                $cursor = $result['pageInfo']['hasNextPage'] ? $result['pageInfo']['endCursor'] : null;
            } while ($cursor !== null);

            return ['documents' => $count, 'products' => $count];
        };

        if ($this->syncRun instanceof SyncRun) {
            $this->withSyncRun($this->syncRun, $runner);

            return;
        }

        $runner();
    }
}
