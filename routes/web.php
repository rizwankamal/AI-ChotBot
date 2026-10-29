<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\SyncController;
use App\Http\Controllers\ShopifyAppHomeController;
use App\Http\Controllers\ShopifyAuthController;
use Illuminate\Support\Facades\Route;

// Embedded App Home (Shopify Admin → Apps → ShopPulse). Uses ?shop= from App Bridge.
Route::get('/', ShopifyAppHomeController::class)->name('shopify.app_home');

Route::get('/auth/shopify/install', [ShopifyAuthController::class, 'showInstall'])->name('shopify.install.show');
Route::match(['get', 'post'], '/auth/shopify', [ShopifyAuthController::class, 'install'])->name('shopify.install');

Route::get('/admin/shops/{shop}/sync', [SyncController::class, 'dashboard'])->name('admin.sync');
Route::get('/admin/shops/{shop}/sync/status', [SyncController::class, 'status'])->name('admin.sync.status');
Route::post('/admin/shops/{shop}/sync/{type}', [SyncController::class, 'start'])
    ->whereIn('type', ['products', 'policies', 'pages', 'blogs'])
    ->name('admin.sync.start');
