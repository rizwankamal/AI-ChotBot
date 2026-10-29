<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SyncShopArticlesJob;
use App\Jobs\SyncShopPagesJob;
use App\Jobs\SyncShopPoliciesJob;
use App\Jobs\SyncShopProductsJob;
use App\Models\Shop;
use App\Models\SyncRun;
use InvalidArgumentException;

class SyncDispatchService
{
    public function __construct(
        private readonly ShopifyInstallService $installer,
    ) {}

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return [
            SyncRun::TYPE_PRODUCTS,
            SyncRun::TYPE_POLICIES,
            SyncRun::TYPE_PAGES,
            SyncRun::TYPE_BLOGS,
        ];
    }

    public function dispatch(Shop $shop, string $type): SyncRun
    {
        if (! in_array($type, $this->types(), true)) {
            throw new InvalidArgumentException("Unknown sync type [{$type}].");
        }

        // Client-credentials tokens expire ~24h; refresh before queueing GraphQL jobs.
        $shop = $this->installer->refreshToken($shop);

        $active = SyncRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', $type)
            ->whereIn('status', [SyncRun::STATUS_QUEUED, SyncRun::STATUS_RUNNING])
            ->latest('id')
            ->first();

        if ($active instanceof SyncRun) {
            return $active;
        }

        $run = SyncRun::query()->create([
            'shop_id' => $shop->id,
            'type' => $type,
            'status' => SyncRun::STATUS_QUEUED,
        ]);

        match ($type) {
            SyncRun::TYPE_PRODUCTS => SyncShopProductsJob::dispatch($shop, $run),
            SyncRun::TYPE_POLICIES => SyncShopPoliciesJob::dispatch($shop, $run),
            SyncRun::TYPE_PAGES => SyncShopPagesJob::dispatch($shop, $run),
            SyncRun::TYPE_BLOGS => SyncShopArticlesJob::dispatch($shop, $run),
        };

        return $run;
    }
}
