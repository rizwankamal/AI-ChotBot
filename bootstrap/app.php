<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Embedded App Home runs inside Shopify Admin iframe; third-party
        // cookie rules often drop the Laravel session, so CSRF mismatches.
        // Sync start is scoped to an installed shop route model instead.
        $middleware->validateCsrfTokens(except: [
            'admin/shops/*/sync/*',
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\AllowShopifyEmbeddedFrame::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
