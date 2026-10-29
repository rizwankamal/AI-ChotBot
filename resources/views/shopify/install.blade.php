<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install ShopPulse AI</title>
    <style>
        :root { color-scheme: light; font-family: ui-sans-serif, system-ui, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f6f6f7; color: #202223; }
        .card { width: min(420px, 92vw); background: #fff; border: 1px solid #e1e3e5; border-radius: 12px; padding: 28px; box-shadow: 0 8px 24px rgba(0,0,0,.04); }
        h1 { margin: 0 0 8px; font-size: 1.4rem; }
        p { margin: 0 0 18px; color: #6d7175; line-height: 1.45; }
        label { display: block; font-size: .85rem; margin-bottom: 6px; }
        input { width: 100%; box-sizing: border-box; border: 1px solid #c9cccf; border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; }
        button { width: 100%; border: 0; border-radius: 8px; padding: 12px; background: #008060; color: #fff; font-weight: 600; cursor: pointer; }
        .error { background: #fff4f4; border: 1px solid #fed3d1; color: #d72c0d; padding: 10px 12px; border-radius: 8px; margin-bottom: 14px; font-size: .9rem; }
    </style>
</head>
<body>
<main class="card">
    <h1>Install ShopPulse AI</h1>
    <p>Local / first-time setup only. After install, open the app from <strong>Shopify Admin → Apps → ShopPulse AI</strong> to sync content — you won’t need this form again.</p>

    @if (! empty($hint))
        <p>{{ $hint }}</p>
    @endif

    @if (! empty($error))
        <div class="error">{{ $error }}</div>
    @endif

    <form method="POST" action="{{ url('/auth/shopify') }}">
        @csrf
        <label for="shop">Store domain</label>
        <input id="shop" name="shop" type="text" placeholder="your-store.myshopify.com" value="{{ request('shop') }}" required>
        <button type="submit">Install app</button>
    </form>
</main>
</body>
</html>
