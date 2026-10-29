<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AiChatStreamController;
use App\Http\Controllers\ShopifyWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('webhooks/shopify')->group(function (): void {
    Route::post('products/create', [ShopifyWebhookController::class, 'productCreated']);
    Route::post('products/update', [ShopifyWebhookController::class, 'productUpdated']);
    Route::post('products/delete', [ShopifyWebhookController::class, 'productDeleted']);
    Route::post('app/uninstalled', [ShopifyWebhookController::class, 'appUninstalled']);
});

Route::prefix('v1')->group(function (): void {
    Route::post('chat/stream', AiChatStreamController::class)
        ->middleware('throttle:ai-chat');
});
