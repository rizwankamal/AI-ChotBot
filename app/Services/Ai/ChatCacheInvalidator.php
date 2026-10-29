<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiSemanticCache;
use App\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Drop stale chat answers after catalog/content sync (exact + semantic cache).
 */
class ChatCacheInvalidator
{
    public function forShop(Shop $shop): void
    {
        try {
            $store = (string) config('ai.exact_cache.store', config('cache.chat_store', 'array'));
            // Exact keys are hashed; flush the whole configured store in local/dev.
            // Production should use a tagged cache — for now prefer correctness.
            if ($store !== 'array') {
                Cache::store($store)->flush();
            } else {
                Cache::store($store)->flush();
            }
        } catch (Throwable) {
            //
        }

        try {
            if (Schema::hasTable('ai_semantic_cache')) {
                AiSemanticCache::query()->where('shop_id', $shop->id)->delete();
            }
        } catch (Throwable) {
            //
        }
    }
}
