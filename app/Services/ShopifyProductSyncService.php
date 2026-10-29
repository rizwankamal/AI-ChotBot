<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateProductEmbeddingJob;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Str;

class ShopifyProductSyncService
{
    /**
     * Upsert a product from a Shopify GraphQL node or REST webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function upsertFromShopifyPayload(
        array $payload,
        bool $dispatchEmbedding = true,
        ?Shop $shop = null,
    ): Product {
        $attributes = $this->mapPayloadToAttributes($payload);

        if ($shop !== null) {
            $attributes['shop_id'] = $shop->id;
        }

        $lookup = ['shopify_id' => $attributes['shopify_id']];

        if ($shop !== null) {
            $lookup['shop_id'] = $shop->id;
        }

        $product = Product::query()->updateOrCreate($lookup, $attributes);

        if ($dispatchEmbedding) {
            GenerateProductEmbeddingJob::dispatch($product);
        }

        return $product;
    }

    /**
     * Delete a local product by Shopify id (GID or numeric).
     */
    public function deleteByShopifyId(string|int $shopifyId, ?Shop $shop = null): bool
    {
        $normalized = $this->normalizeShopifyId($shopifyId);

        $query = Product::query()->where(function ($builder) use ($normalized, $shopifyId): void {
            $builder->where('shopify_id', $normalized)
                ->orWhere('shopify_id', (string) $shopifyId);
        });

        if ($shop !== null) {
            $query->where('shop_id', $shop->id);
        }

        return $query->delete() > 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     shopify_id: string,
     *     handle: ?string,
     *     title: string,
     *     description: ?string,
     *     price: string,
     *     inventory_count: int,
     *     category: ?string,
     *     image_url: ?string,
     *     options: list<array{name: string, values: list<string>}>,
     *     variants: list<array<string, mixed>>
     * }
     */
    public function mapPayloadToAttributes(array $payload): array
    {
        $shopifyId = $payload['id'] ?? $payload['admin_graphql_api_id'] ?? null;

        if ($shopifyId === null) {
            throw new \InvalidArgumentException('Shopify product payload is missing an id.');
        }

        $descriptionHtml = $payload['descriptionHtml']
            ?? $payload['body_html']
            ?? $payload['description']
            ?? null;

        $description = is_string($descriptionHtml)
            ? trim(html_entity_decode(strip_tags($descriptionHtml)))
            : null;

        $title = (string) ($payload['title'] ?? 'Untitled product');
        $handle = $payload['handle'] ?? null;
        $handle = is_string($handle) && trim($handle) !== ''
            ? Str::slug(trim($handle))
            : Str::slug($title);

        return [
            'shopify_id' => $this->normalizeShopifyId($shopifyId),
            'handle' => $handle !== '' ? $handle : null,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'price' => $this->resolvePrice($payload),
            'inventory_count' => $this->resolveInventory($payload),
            'category' => $this->resolveCategory($payload),
            'image_url' => $this->resolveImageUrl($payload),
            'options' => $this->resolveOptions($payload),
            'variants' => $this->resolveVariants($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveImageUrl(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'featuredImage.url'),
            data_get($payload, 'image.src'),
            data_get($payload, 'images.0.src'),
            data_get($payload, 'images.edges.0.node.url'),
        ];

        foreach ($candidates as $url) {
            if (is_string($url) && str_starts_with($url, 'http')) {
                return Str::limit($url, 2048, '');
            }
        }

        return null;
    }

    public function normalizeShopifyId(string|int $id): string
    {
        $id = (string) $id;

        if (str_starts_with($id, 'gid://')) {
            return $id;
        }

        return 'gid://shopify/Product/'.$id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolvePrice(array $payload): string
    {
        $variantEdges = data_get($payload, 'variants.edges');

        if (is_array($variantEdges) && $variantEdges !== []) {
            $price = data_get($variantEdges, '0.node.price');

            if ($price !== null && $price !== '') {
                return number_format((float) $price, 2, '.', '');
            }
        }

        $variants = $payload['variants'] ?? null;

        if (is_array($variants) && $variants !== [] && ! isset($variants['edges'])) {
            $price = data_get($variants, '0.price');

            if ($price !== null && $price !== '') {
                return number_format((float) $price, 2, '.', '');
            }
        }

        return '0.00';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveInventory(array $payload): int
    {
        if (isset($payload['totalInventory'])) {
            return max(0, (int) $payload['totalInventory']);
        }

        if (isset($payload['total_inventory'])) {
            return max(0, (int) $payload['total_inventory']);
        }

        $variantEdges = data_get($payload, 'variants.edges');

        if (is_array($variantEdges)) {
            return (int) collect($variantEdges)
                ->sum(static fn (array $edge): int => (int) data_get($edge, 'node.inventoryQuantity', 0));
        }

        $variants = $payload['variants'] ?? null;

        if (is_array($variants) && ! isset($variants['edges'])) {
            return (int) collect($variants)
                ->sum(static fn (array $variant): int => (int) ($variant['inventory_quantity'] ?? 0));
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCategory(array $payload): ?string
    {
        $category = $payload['productType']
            ?? $payload['product_type']
            ?? null;

        if (! is_string($category) || trim($category) === '') {
            return null;
        }

        return Str::limit(trim($category), 255, '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{name: string, values: list<string>}>
     */
    public function resolveOptions(array $payload): array
    {
        $rawOptions = $payload['options'] ?? null;

        if (! is_array($rawOptions) || $rawOptions === []) {
            return $this->optionsFromVariants($payload);
        }

        $options = [];

        foreach ($rawOptions as $option) {
            if (! is_array($option)) {
                continue;
            }

            $name = trim((string) ($option['name'] ?? ''));

            // Skip Shopify's default "Title" option when there is only Default Title.
            if ($name === '' || strcasecmp($name, 'Title') === 0) {
                $values = $option['values'] ?? [];
                if (is_array($values) && count($values) === 1 && strcasecmp((string) $values[0], 'Default Title') === 0) {
                    continue;
                }
                if (strcasecmp($name, 'Title') === 0 && (! is_array($values) || $values === [])) {
                    continue;
                }
            }

            if ($name === '') {
                continue;
            }

            $values = [];
            foreach ($option['values'] ?? [] as $value) {
                if (is_string($value) && trim($value) !== '' && strcasecmp($value, 'Default Title') !== 0) {
                    $values[] = trim($value);
                }
            }

            if ($values === []) {
                continue;
            }

            $options[] = [
                'name' => Str::limit($name, 100, ''),
                'values' => array_values(array_unique($values)),
            ];
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function resolveVariants(array $payload): array
    {
        $rows = [];

        $edges = data_get($payload, 'variants.edges');

        if (is_array($edges)) {
            foreach ($edges as $edge) {
                $node = is_array($edge) ? ($edge['node'] ?? null) : null;
                if (! is_array($node)) {
                    continue;
                }

                $mapped = $this->mapVariantNode($node);
                if ($mapped !== null) {
                    $rows[] = $mapped;
                }
            }

            return $rows;
        }

        $variants = $payload['variants'] ?? null;

        if (! is_array($variants)) {
            return [];
        }

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            // REST webhook shape: option1/option2/option3 + options product-level.
            if (isset($variant['option1']) || isset($variant['inventory_quantity'])) {
                $mapped = $this->mapRestVariant($variant, $payload);
                if ($mapped !== null) {
                    $rows[] = $mapped;
                }

                continue;
            }

            $mapped = $this->mapVariantNode($variant);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function mapVariantNode(array $node): ?array
    {
        $id = $node['id'] ?? null;
        if ($id === null) {
            return null;
        }

        $selected = [];
        foreach ($node['selectedOptions'] ?? [] as $opt) {
            if (! is_array($opt)) {
                continue;
            }
            $name = trim((string) ($opt['name'] ?? ''));
            $value = trim((string) ($opt['value'] ?? ''));
            if ($name === '' || $value === '' || strcasecmp($value, 'Default Title') === 0) {
                continue;
            }
            $selected[$name] = $value;
        }

        return [
            'id' => (string) $id,
            'title' => (string) ($node['title'] ?? ''),
            'price' => isset($node['price']) ? number_format((float) $node['price'], 2, '.', '') : null,
            'inventory' => max(0, (int) ($node['inventoryQuantity'] ?? $node['inventory_quantity'] ?? 0)),
            'available' => (bool) ($node['availableForSale'] ?? (($node['inventoryQuantity'] ?? 0) > 0)),
            'options' => $selected,
        ];
    }

    /**
     * @param  array<string, mixed>  $variant
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function mapRestVariant(array $variant, array $payload): ?array
    {
        $id = $variant['admin_graphql_api_id'] ?? $variant['id'] ?? null;
        if ($id === null) {
            return null;
        }

        if (is_int($id) || (is_string($id) && ! str_starts_with($id, 'gid://'))) {
            $id = 'gid://shopify/ProductVariant/'.$id;
        }

        $optionNames = [];
        foreach ($payload['options'] ?? [] as $index => $option) {
            if (is_array($option) && isset($option['name'])) {
                $optionNames[$index + 1] = (string) $option['name'];
            }
        }

        $selected = [];
        foreach ([1, 2, 3] as $i) {
            $value = $variant['option'.$i] ?? null;
            if (! is_string($value) || trim($value) === '' || strcasecmp($value, 'Default Title') === 0) {
                continue;
            }
            $name = $optionNames[$i] ?? 'Option '.$i;
            if (strcasecmp($name, 'Title') === 0) {
                continue;
            }
            $selected[$name] = trim($value);
        }

        $qty = max(0, (int) ($variant['inventory_quantity'] ?? 0));

        return [
            'id' => (string) $id,
            'title' => (string) ($variant['title'] ?? ''),
            'price' => isset($variant['price']) ? number_format((float) $variant['price'], 2, '.', '') : null,
            'inventory' => $qty,
            'available' => $qty > 0,
            'options' => $selected,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{name: string, values: list<string>}>
     */
    private function optionsFromVariants(array $payload): array
    {
        $variants = $this->resolveVariants($payload);
        /** @var array<string, array<string, true>> $bucket */
        $bucket = [];

        foreach ($variants as $variant) {
            foreach ($variant['options'] ?? [] as $name => $value) {
                if (! is_string($name) || ! is_string($value)) {
                    continue;
                }
                $bucket[$name][$value] = true;
            }
        }

        $options = [];
        foreach ($bucket as $name => $values) {
            $options[] = [
                'name' => $name,
                'values' => array_keys($values),
            ];
        }

        return $options;
    }
}
