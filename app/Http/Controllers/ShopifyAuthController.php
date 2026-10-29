<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SyncShopProductsJob;
use App\Services\ShopifyInstallService;
use App\Services\ShopifyWebhookRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ShopifyAuthController extends Controller
{
    /**
     * Show the install landing page.
     */
    public function showInstall(Request $request): View
    {
        return view('shopify.install');
    }

    /**
     * Install via client credentials, register webhooks, and kick off product sync.
     */
    public function install(
        Request $request,
        ShopifyInstallService $installer,
        ShopifyWebhookRegistrationService $webhooks,
    ): View|RedirectResponse {
        $request->validate([
            'shop' => ['required', 'string'],
        ]);

        try {
            $shop = $installer->install((string) $request->string('shop'));

            $webhooks->registerForShop($shop);

            SyncShopProductsJob::dispatch($shop);

            return redirect()
                ->route('shopify.app_home', ['shop' => $shop->shop_domain])
                ->with('status', 'Installed. Product sync queued. Sync policies, pages, and blogs when ready.');
        } catch (Throwable $exception) {
            report($exception);

            return view('shopify.install', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
