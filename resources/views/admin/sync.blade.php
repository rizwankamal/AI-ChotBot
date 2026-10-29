<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="shopify-api-key" content="{{ config('services.shopify.api_key') }}" />
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <script src="https://cdn.shopify.com/shopifycloud/polaris.js"></script>
    <title>ShopPulse Sync</title>
</head>
<body>
@php
    $badgeTone = static function (?string $status): string {
        return match ($status) {
            'completed' => 'success',
            'failed' => 'critical',
            'queued', 'running' => 'caution',
            default => 'neutral',
        };
    };
@endphp

<s-page heading="ShopPulse Sync">
    <s-banner tone="info" heading="Sync store content for AI chat">
        Push products, policies, pages, and blogs into ShopPulse whenever you update the store.
        Shop: {{ $shop->shop_domain }}
    </s-banner>

    @if (session('status'))
        <s-banner tone="success" heading="Queued" dismissible>{{ session('status') }}</s-banner>
    @endif

    <s-section heading="Content sources">
        <s-paragraph color="subdued">
            Each sync queues embedding jobs on <s-text type="strong">ai-processing</s-text>. Keep a queue worker running locally.
        </s-paragraph>

        <s-grid gridTemplateColumns="1fr 1fr" gap="base" style="margin-block-start: 1rem;">
            @foreach ($types as $type => $meta)
                @php
                    /** @var \App\Models\SyncRun|null $run */
                    $run = $latestByType[$type] ?? null;
                    $status = $run?->status ?? 'idle';
                    $busy = in_array($status, ['queued', 'running'], true);
                @endphp
                <s-box
                    padding="base"
                    border="base"
                    borderRadius="base"
                    background="base"
                    data-type="{{ $type }}"
                >
                    <s-stack gap="small-200">
                        <s-heading>{{ $meta['label'] }}</s-heading>
                        <s-paragraph color="subdued">{{ $meta['description'] }}</s-paragraph>
                        <s-stack direction="inline" gap="small-200" alignItems="center">
                            <s-badge
                                tone="{{ $badgeTone($status) }}"
                                data-status-badge
                            >{{ $status }}</s-badge>
                            <s-text color="subdued" data-count>{{ $meta['count'] }} indexed</s-text>
                        </s-stack>
                        <s-button
                            variant="primary"
                            data-sync-btn
                            data-sync-type="{{ $type }}"
                            @if ($busy) disabled @endif
                        >{{ $busy ? 'Syncing…' : 'Sync now' }}</s-button>
                    </s-stack>
                </s-box>
            @endforeach
        </s-grid>
    </s-section>

    <s-section heading="Storefront widget">
        <s-paragraph color="subdued">
            Enable <s-text type="strong">ShopPulse Chat</s-text> under Online Store → Themes → Customize → App embeds.
        </s-paragraph>
    </s-section>
</s-page>

<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const statusUrl = @json(route('admin.sync.status', $shop));
    const startBase = @json(url('/admin/shops/'.$shop->shop_domain.'/sync'));

    const toast = (message) => {
        try {
            shopify?.toast?.show(message);
        } catch (e) {}
    };

    const setBusy = (panel, busy, status) => {
        const badge = panel.querySelector('[data-status-badge]');
        const btn = panel.querySelector('[data-sync-btn]');
        if (badge) {
            badge.textContent = status || (busy ? 'running' : 'idle');
            badge.setAttribute('tone', busy ? 'caution' : (status === 'completed' ? 'success' : status === 'failed' ? 'critical' : 'neutral'));
        }
        if (btn) {
            if (busy) btn.setAttribute('disabled', '');
            else btn.removeAttribute('disabled');
            btn.textContent = busy ? 'Syncing…' : 'Sync now';
        }
    };

    document.querySelectorAll('[data-sync-btn]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const type = btn.getAttribute('data-sync-type');
            const panel = btn.closest('[data-type]');
            if (!type || !panel) return;

            setBusy(panel, true, 'queued');
            try {
                const res = await fetch(`${startBase}/${type}`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.message || `Sync failed (${res.status})`);
                toast(`${type} sync queued`);
                setBusy(panel, true, data.run?.status || 'queued');
            } catch (e) {
                setBusy(panel, false, 'failed');
                toast(e.message || 'Could not start sync');
            }
        });
    });

    const poll = async () => {
        try {
            const res = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            const latest = data.latest || {};
            document.querySelectorAll('[data-type]').forEach((panel) => {
                const type = panel.getAttribute('data-type');
                const run = latest[type];
                const status = run?.status || 'idle';
                const busy = status === 'queued' || status === 'running';
                setBusy(panel, busy, status);
            });
        } catch (e) {}
    };

    setInterval(poll, 4000);
})();
</script>
</body>
</html>
