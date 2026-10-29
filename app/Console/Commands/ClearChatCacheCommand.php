<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiSemanticCache;
use App\Models\Shop;
use App\Services\Ai\ChatCacheInvalidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ClearChatCacheCommand extends Command
{
    protected $signature = 'shoppulse:clear-chat-cache {shop? : myshopify domain}';

    protected $description = 'Clear exact + semantic chat caches (fixes stale no-policy answers)';

    public function handle(ChatCacheInvalidator $invalidator): int
    {
        $shopArg = $this->argument('shop');

        if (is_string($shopArg) && $shopArg !== '') {
            $shop = Shop::query()->where('shop_domain', $shopArg)->first();

            if ($shop === null) {
                $this->error("Shop [{$shopArg}] not found.");

                return self::FAILURE;
            }

            $invalidator->forShop($shop);
            $this->info("Cleared chat cache for {$shop->shop_domain}");

            return self::SUCCESS;
        }

        foreach (Shop::query()->whereNull('uninstalled_at')->cursor() as $shop) {
            $invalidator->forShop($shop);
            $this->line('Cleared '.$shop->shop_domain);
        }

        if (Schema::hasTable('ai_semantic_cache')) {
            AiSemanticCache::query()->delete();
            $this->info('Truncated ai_semantic_cache');
        }

        return self::SUCCESS;
    }
}
