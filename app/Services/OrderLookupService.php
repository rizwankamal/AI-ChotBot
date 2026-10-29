<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Live Shopify Admin order lookup (not RAG) — status, shipping, tracking.
 */
class OrderLookupService
{
    public function __construct(
        private readonly ShopifyClientService $shopify,
    ) {}

    /**
     * @return array{
     *     found: bool,
     *     count: int,
     *     orders: list<array<string, mixed>>,
     *     note: string,
     *     error?: string
     * }
     */
    public function lookup(
        ?string $orderNumber = null,
        ?string $email = null,
        ?string $phone = null,
        ?Shop $shop = null,
        int $limit = 5,
    ): array {
        $orderNumber = $this->normalizeOrderNumber($orderNumber);
        $email = $this->normalizeEmail($email);
        $phone = $this->normalizePhone($phone);

        if ($orderNumber === null && $email === null && $phone === null) {
            return [
                'found' => false,
                'count' => 0,
                'orders' => [],
                'note' => 'Ask the customer for an order number (e.g. #1001) and the email used at checkout, then call again.',
            ];
        }

        $query = $this->buildSearchQuery($orderNumber, $email, $phone);

        try {
            $client = $shop !== null ? $this->shopify->forShop($shop) : $this->shopify;
            $rawOrders = $client->lookupOrders($query, max(1, min($limit, 10)));
        } catch (Throwable $exception) {
            Log::warning('Order lookup failed', [
                'shop_id' => $shop?->id,
                'query' => $query,
                'message' => $exception->getMessage(),
            ]);

            return [
                'found' => false,
                'count' => 0,
                'orders' => [],
                'note' => 'Order lookup is temporarily unavailable. Ask the customer to check their order confirmation email or contact the store.',
                'error' => 'shopify_unavailable',
            ];
        }

        $orders = [];

        foreach ($rawOrders as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $mapped = $this->mapOrder($raw);

            // When both order # and email are given, require an email match.
            if ($orderNumber !== null && $email !== null) {
                $orderEmail = strtolower((string) ($mapped['email'] ?? ''));
                if ($orderEmail === '' || $orderEmail !== $email) {
                    continue;
                }
            }

            $orders[] = $mapped;
        }

        if ($orders === []) {
            return [
                'found' => false,
                'count' => 0,
                'orders' => [],
                'note' => $orderNumber !== null && $email !== null
                    ? 'No order matched that number and email. Ask the customer to double-check both.'
                    : 'No matching orders found. Ask for the order number and checkout email.',
            ];
        }

        return [
            'found' => true,
            'count' => count($orders),
            'orders' => $orders,
            'note' => 'Answer only from these orders. State financial/fulfillment status, tracking, and estimated delivery when present. Do not invent ETAs.',
        ];
    }

    /**
     * Shopify Admin search syntax for the orders connection.
     */
    public function buildSearchQuery(?string $orderNumber, ?string $email, ?string $phone): string
    {
        $parts = [];

        if ($orderNumber !== null) {
            $parts[] = 'name:'.$orderNumber;
        }

        if ($email !== null) {
            $parts[] = 'email:'.$email;
        }

        if ($phone !== null) {
            $parts[] = 'phone:'.$phone;
        }

        return implode(' ', $parts);
    }

    public function normalizeOrderNumber(?string $orderNumber): ?string
    {
        if (! is_string($orderNumber)) {
            return null;
        }

        $trimmed = trim($orderNumber);
        if ($trimmed === '') {
            return null;
        }

        // Accept "#1001", "1001", "Order 1001".
        if (preg_match('/#?\s*(\d+)\s*$/', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return preg_replace('/^#/', '', $trimmed) ?: null;
    }

    public function normalizeEmail(?string $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $trimmed = strtolower(trim($email));
        if ($trimmed === '' || ! filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $trimmed;
    }

    public function normalizePhone(?string $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        $digits = preg_replace('/[^\d+]/', '', trim($phone)) ?? '';

        return $digits !== '' ? $digits : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function mapOrder(array $raw): array
    {
        $money = data_get($raw, 'totalPriceSet.shopMoney');

        $fulfillments = [];
        foreach (data_get($raw, 'fulfillments', []) as $fulfillment) {
            if (! is_array($fulfillment)) {
                continue;
            }

            $tracking = [];
            foreach (data_get($fulfillment, 'trackingInfo', []) as $track) {
                if (! is_array($track)) {
                    continue;
                }

                $tracking[] = [
                    'company' => $track['company'] ?? null,
                    'number' => $track['number'] ?? null,
                    'url' => $track['url'] ?? null,
                ];
            }

            $fulfillments[] = [
                'status' => $fulfillment['status'] ?? null,
                'display_status' => $fulfillment['displayStatus'] ?? null,
                'estimated_delivery_at' => $fulfillment['estimatedDeliveryAt'] ?? null,
                'delivered_at' => $fulfillment['deliveredAt'] ?? null,
                'tracking' => $tracking,
            ];
        }

        $lineItems = [];
        foreach (data_get($raw, 'lineItems.edges', []) as $edge) {
            $node = is_array($edge) ? ($edge['node'] ?? null) : null;
            if (! is_array($node)) {
                continue;
            }

            $lineItems[] = [
                'title' => $node['title'] ?? null,
                'quantity' => isset($node['quantity']) ? (int) $node['quantity'] : null,
                'sku' => $node['sku'] ?? null,
                'variant' => $node['variantTitle'] ?? null,
            ];
        }

        $shipping = data_get($raw, 'shippingAddress');

        return [
            'name' => $raw['name'] ?? null,
            'email' => isset($raw['email']) ? strtolower((string) $raw['email']) : null,
            'created_at' => $raw['createdAt'] ?? null,
            'processed_at' => $raw['processedAt'] ?? null,
            'financial_status' => $raw['displayFinancialStatus'] ?? null,
            'fulfillment_status' => $raw['displayFulfillmentStatus'] ?? null,
            'cancelled_at' => $raw['cancelledAt'] ?? null,
            'cancel_reason' => $raw['cancelReason'] ?? null,
            'status_page_url' => $raw['statusPageUrl'] ?? null,
            'total' => is_array($money) ? [
                'amount' => $money['amount'] ?? null,
                'currency' => $money['currencyCode'] ?? null,
            ] : null,
            'shipping_city' => is_array($shipping) ? ($shipping['city'] ?? null) : null,
            'shipping_country' => is_array($shipping) ? ($shipping['country'] ?? null) : null,
            'fulfillments' => $fulfillments,
            'line_items' => $lineItems,
        ];
    }
}
