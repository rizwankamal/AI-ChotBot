<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\ShopifyInstallService;
use Illuminate\Console\Command;
use Throwable;

class RefreshShopifyTokenCommand extends Command
{
    protected $signature = 'shopify:refresh-token {shop? : myshopify domain}';

    protected $description = 'Refresh client-credentials access token(s) for installed shop(s)';

    public function handle(ShopifyInstallService $installer): int
    {
        $shopArg = $this->argument('shop');

        $query = Shop::query()->whereNull('uninstalled_at');

        if (is_string($shopArg) && $shopArg !== '') {
            $query->where('shop_domain', $shopArg);
        }

        $shops = $query->get();

        if ($shops->isEmpty()) {
            $this->error('No installed shops found.');

            return self::FAILURE;
        }

        foreach ($shops as $shop) {
            try {
                $fresh = $installer->refreshToken($shop, force: true);
                $this->info(sprintf(
                    'Refreshed %s (scopes: %s, expires: %s)',
                    $fresh->shop_domain,
                    $fresh->scopes ?? 'n/a',
                    $fresh->token_expires_at?->toIso8601String() ?? 'n/a',
                ));
            } catch (Throwable $exception) {
                $this->error($shop->shop_domain.': '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
