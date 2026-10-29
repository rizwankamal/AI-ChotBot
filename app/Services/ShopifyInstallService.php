<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Shop;
use App\Support\ShopifyRequest;
use Carbon\Carbon;
use RuntimeException;
use Shopify\App\ShopifyApp;

class ShopifyInstallService
{
    public function __construct(
        private readonly ShopifyApp $shopifyApp,
    ) {}

    /**
     * Exchange client credentials for an offline token and persist the shop.
     */
    public function install(string $shopDomain): Shop
    {
        $subdomain = ShopifyRequest::subdomain($shopDomain);
        $fullDomain = ShopifyRequest::fullDomain($subdomain);

        if ($subdomain === '' || ! preg_match('/^[a-z0-9][a-z0-9\-]*$/', $subdomain)) {
            throw new RuntimeException('Shop domain must be a valid *.myshopify.com host.');
        }

        return $this->persistToken($fullDomain, $subdomain);
    }

    /**
     * Client-credentials tokens expire (~24h). Refresh before GraphQL sync jobs.
     */
    public function refreshToken(Shop $shop, bool $force = false): Shop
    {
        if (! $force && ! $this->tokenNeedsRefresh($shop)) {
            return $shop;
        }

        $subdomain = ShopifyRequest::subdomain($shop->shop_domain);

        return $this->persistToken($shop->shop_domain, $subdomain, $shop);
    }

    public function tokenNeedsRefresh(Shop $shop): bool
    {
        if ($shop->access_token === '' || $shop->access_token === null) {
            return true;
        }

        $expiresAt = $shop->token_expires_at;

        if ($expiresAt === null) {
            // Legacy rows without expiry — refresh to be safe before API calls.
            return true;
        }

        return $expiresAt->lessThanOrEqualTo(now()->addMinutes(5));
    }

    private function persistToken(string $fullDomain, string $subdomain, ?Shop $existing = null): Shop
    {
        $result = $this->shopifyApp->exchangeUsingClientCredentials(shop: $subdomain);

        if (! $result->ok || $result->accessToken === null) {
            throw new RuntimeException(sprintf(
                'Shopify client credentials exchange failed [%s]: %s',
                $result->log->code,
                $result->log->detail,
            ));
        }

        $expires = $result->accessToken->expires;
        $expiresAt = is_string($expires) && $expires !== ''
            ? Carbon::parse($expires)
            : now()->addHours(23);

        $attributes = [
            'access_token' => $result->accessToken->token,
            'scopes' => $result->accessToken->scope !== ''
                ? $result->accessToken->scope
                : (string) config('services.shopify.scopes'),
            'token_expires_at' => $expiresAt,
            'installed_at' => $existing?->installed_at ?? now(),
            'uninstalled_at' => null,
        ];

        if ($existing instanceof Shop) {
            $existing->fill($attributes);
            $existing->save();

            return $existing->refresh();
        }

        return Shop::query()->updateOrCreate(
            ['shop_domain' => $fullDomain],
            $attributes,
        );
    }
}
