<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ShopPulse AI Installed</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: ui-sans-serif, system-ui, sans-serif; background: #f6f6f7; }
        .card { width: min(520px, 92vw); background: #fff; border: 1px solid #e1e3e5; border-radius: 12px; padding: 28px; }
        h1 { margin: 0 0 8px; }
        p, li { color: #6d7175; line-height: 1.5; }
        code { background: #f1f2f3; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
<main class="card">
    <h1>Installed on {{ $shop->shop_domain }}</h1>
    <p>ShopPulse is connected. Next steps:</p>
    <ol>
        <li>Product sync + embedding jobs are queued automatically.</li>
        <li>Webhooks for create / update / delete / uninstall are registered.</li>
        <li>{{ $widgetHint }}</li>
        <li>Storefront widget posts to <code>/api/v1/chat/stream</code> with <code>shop={{ $shop->shop_domain }}</code>.</li>
    </ol>
</main>
</body>
</html>
