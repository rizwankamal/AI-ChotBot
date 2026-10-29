<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\ShopifyInstallService;
use App\Support\ShopifyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Embedded Shopify App Home entrypoint (application_url).
 *
 * Shopify opens this with ?shop=…&host=… — do not ask for the store domain again.
 */
class ShopifyAppHomeController extends Controller
{
    public function __invoke(
        Request $request,
        Admin\SyncController $sync,
        ShopifyInstallService $installer,
    ): View|RedirectResponse {
        $shopDomain = $request->query('shop');

        if (! is_string($shopDomain) || trim($shopDomain) === '') {
            return view('shopify.install', [
                'hint' => 'Open ShopPulse from Shopify Admin → Apps. This install form is only for local setup.',
            ]);
        }

        $normalized = ShopifyRequest::fullDomain($shopDomain);

        $shop = Shop::query()->where('shop_domain', $normalized)->first();

        if ($shop === null || ! $shop->isInstalled()) {
            return redirect()->to('/auth/shopify/install?shop='.urlencode($normalized));
        }

        try {
            // Keep client-credentials token fresh while merchant uses App Home.
            $shop = $installer->refreshToken($shop);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->to('/auth/shopify/install?shop='.urlencode($normalized))
                ->with('error', 'Shopify token expired. Re-run install to refresh credentials.');
        }

        return $sync->dashboard($shop);
    }
}
