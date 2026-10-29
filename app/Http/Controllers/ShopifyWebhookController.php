<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\ShopifyProductSyncService;
use App\Support\ShopifyRequest;
use Illuminate\Http\Request;
use Shopify\App\ShopifyApp;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ShopifyWebhookController extends Controller
{
    public function __construct(
        private readonly ShopifyProductSyncService $sync,
        private readonly ShopifyApp $shopifyApp,
    ) {}

    /**
     * Handle products/create webhooks.
     */
    public function productCreated(Request $request): SymfonyResponse
    {
        $verified = $this->verify($request);

        if ($verified instanceof SymfonyResponse) {
            return $verified;
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $this->sync->upsertFromShopifyPayload($payload, shop: $this->resolveShop($verified));

        return response()->noContent();
    }

    /**
     * Handle products/update webhooks.
     */
    public function productUpdated(Request $request): SymfonyResponse
    {
        $verified = $this->verify($request);

        if ($verified instanceof SymfonyResponse) {
            return $verified;
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $this->sync->upsertFromShopifyPayload($payload, shop: $this->resolveShop($verified));

        return response()->noContent();
    }

    /**
     * Handle products/delete webhooks.
     */
    public function productDeleted(Request $request): SymfonyResponse
    {
        $verified = $this->verify($request);

        if ($verified instanceof SymfonyResponse) {
            return $verified;
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $shopifyId = $payload['id'] ?? $payload['admin_graphql_api_id'] ?? null;

        if ($shopifyId !== null) {
            $this->sync->deleteByShopifyId($shopifyId, $this->resolveShop($verified));
        }

        return response()->noContent();
    }

    /**
     * Handle app/uninstalled webhooks.
     */
    public function appUninstalled(Request $request): SymfonyResponse
    {
        $verified = $this->verify($request);

        if ($verified instanceof SymfonyResponse) {
            return $verified;
        }

        $shop = $this->resolveShop($verified);

        if ($shop !== null) {
            $shop->update([
                'access_token' => '',
                'uninstalled_at' => now(),
            ]);
        }

        return response()->noContent();
    }

    /**
     * @return string|SymfonyResponse Verified shop subdomain, or a package failure response.
     */
    private function verify(Request $request): string|SymfonyResponse
    {
        $result = $this->shopifyApp->verifyWebhookReq(
            ShopifyRequest::fromLaravel($request)
        );

        if (! $result->ok || $result->shop === null || $result->shop === '') {
            return ShopifyRequest::toResponse($result);
        }

        return $result->shop;
    }

    private function resolveShop(string $subdomain): ?Shop
    {
        $fullDomain = ShopifyRequest::fullDomain($subdomain);

        return Shop::query()
            ->where('shop_domain', $fullDomain)
            ->whereNull('uninstalled_at')
            ->first();
    }
}
