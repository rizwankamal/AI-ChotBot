<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\ShopifyClientService;
use App\Services\ShopifyProductSyncService;
use Illuminate\Console\Command;

class ShopifySyncProductsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:sync-products
                            {--shop= : myshopify.com domain for a multi-tenant install}
                            {--page-size=50 : Number of products to fetch per GraphQL page}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import Shopify products via Admin GraphQL and queue embedding generation';

    /**
     * Execute the console command.
     */
    public function handle(
        ShopifyClientService $shopify,
        ShopifyProductSyncService $sync,
    ): int {
        $pageSize = max(1, min(250, (int) $this->option('page-size')));
        $shop = $this->resolveShop();
        $client = $shop instanceof Shop ? $shopify->forShop($shop) : $shopify;

        $cursor = null;
        $imported = 0;
        $page = 0;

        $this->info('Starting Shopify product sync'.($shop ? " for {$shop->shop_domain}" : '').'...');

        do {
            $page++;
            $result = $client->fetchProducts($pageSize, $cursor);
            $products = $result['products'];
            $pageInfo = $result['pageInfo'];

            foreach ($products as $productNode) {
                $product = $sync->upsertFromShopifyPayload($productNode, shop: $shop);
                $imported++;
                $this->line("  • Upserted #{$product->id}: {$product->title}");
            }

            $cursor = $pageInfo['hasNextPage'] ? $pageInfo['endCursor'] : null;

            $this->comment("Page {$page}: ".count($products).' product(s)');
        } while ($cursor !== null);

        $this->info("Sync complete. Upserted {$imported} product(s). Embedding jobs have been queued.");

        return self::SUCCESS;
    }

    private function resolveShop(): ?Shop
    {
        $shopDomain = $this->option('shop');

        if (! is_string($shopDomain) || $shopDomain === '') {
            return null;
        }

        $shopDomain = strtolower(rtrim(preg_replace('#^https?://#', '', $shopDomain) ?? $shopDomain, '/'));

        $shop = Shop::query()
            ->where('shop_domain', $shopDomain)
            ->whereNull('uninstalled_at')
            ->first();

        if ($shop === null) {
            $this->error("Installed shop [{$shopDomain}] was not found.");
            exit(self::FAILURE);
        }

        return $shop;
    }
}
