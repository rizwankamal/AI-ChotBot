<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Shopify\App\ShopifyApp;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ShopifyApp::class, function (): ShopifyApp {
            return new ShopifyApp(
                (string) config('services.shopify.api_key', 'missing-api-key'),
                (string) config('services.shopify.api_secret', 'missing-api-secret'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('ai-chat', function (Request $request): Limit {
            $shopDomain = $request->input('shop')
                ?? $request->header('X-Shopify-Shop-Domain');

            if (is_string($shopDomain) && trim($shopDomain) !== '') {
                $key = 'shop:'.strtolower(trim($shopDomain));
                $max = max(1, (int) config('ai.rate_limit.shop_per_minute', 120));

                return Limit::perMinute($max)->by($key);
            }

            $max = max(1, (int) config('ai.rate_limit.guest_per_minute', 20));

            return Limit::perMinute($max)->by($request->ip() ?? 'guest');
        });
    }
}

