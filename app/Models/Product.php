<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\VectorStorage;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    /**
     * Pending embedding written via STRING_TO_VECTOR after insert/update.
     *
     * @var array<int, float>|null
     */
    private ?array $pendingEmbedding = null;

    /**
     * Cached decoded VECTOR for this request (avoids repeat VECTOR_TO_STRING).
     *
     * @var array<int, float>|null
     */
    private ?array $decodedEmbedding = null;

    private bool $decodedEmbeddingLoaded = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'shop_id',
        'shopify_id',
        'handle',
        'title',
        'description',
        'price',
        'inventory_count',
        'category',
        'image_url',
        'options',
        'variants',
        'embedding',
    ];

    /**
     * Public storefront URL for chat deep-links.
     */
    public function storefrontUrl(?string $shopDomain = null): ?string
    {
        $domain = $shopDomain
            ?? $this->shop?->shop_domain
            ?? null;

        if (! is_string($domain) || trim($domain) === '') {
            return null;
        }

        $handle = is_string($this->handle) && $this->handle !== ''
            ? $this->handle
            : \Illuminate\Support\Str::slug($this->title);

        if ($handle === '') {
            return null;
        }

        $domain = strtolower(rtrim(preg_replace('#^https?://#', '', $domain) ?? $domain, '/'));

        return 'https://'.$domain.'/products/'.$handle;
    }

    protected static function booted(): void
    {
        static::saved(function (Product $product): void {
            if ($product->pendingEmbedding === null) {
                return;
            }

            VectorStorage::saveProductEmbedding((int) $product->id, $product->pendingEmbedding);
            $product->decodedEmbedding = $product->pendingEmbedding;
            $product->decodedEmbeddingLoaded = true;
            $product->pendingEmbedding = null;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'inventory_count' => 'integer',
            'options' => 'array',
            'variants' => 'array',
        ];
    }

    /**
     * Option names/values with at least one in-stock (or available) variant.
     *
     * @return list<array{name: string, values: list<string>}>
     */
    public function availableOptions(): array
    {
        $options = is_array($this->options) ? $this->options : [];
        $variants = is_array($this->variants) ? $this->variants : [];

        if ($options === []) {
            return [];
        }

        /** @var array<string, array<string, true>> $inStockByOption */
        $inStockByOption = [];

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $qty = (int) ($variant['inventory'] ?? 0);
            $available = (bool) ($variant['available'] ?? false);

            if ($qty <= 0 && ! $available) {
                continue;
            }

            $selected = $variant['options'] ?? [];

            if (! is_array($selected)) {
                continue;
            }

            foreach ($selected as $name => $value) {
                if (! is_string($name) || ! is_string($value) || $name === '' || $value === '') {
                    continue;
                }

                $inStockByOption[$name][$value] = true;
            }
        }

        $result = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $name = trim((string) ($option['name'] ?? ''));
            $values = $option['values'] ?? [];

            if ($name === '' || ! is_array($values)) {
                continue;
            }

            $filtered = [];

            foreach ($values as $value) {
                if (! is_string($value) || $value === '') {
                    continue;
                }

                // Prefer in-stock values; if we have no variant stock map, keep declared values.
                if ($inStockByOption === [] || isset($inStockByOption[$name][$value])) {
                    $filtered[] = $value;
                }
            }

            if ($filtered === [] && isset($inStockByOption[$name])) {
                $filtered = array_keys($inStockByOption[$name]);
            }

            if ($filtered === []) {
                continue;
            }

            $result[] = [
                'name' => $name,
                'values' => array_values(array_unique($filtered)),
            ];
        }

        return $result;
    }

    /**
     * Embeddings as float arrays. Native VECTOR columns use STRING_TO_VECTOR
     * on write and VECTOR_TO_STRING on read (PDO cannot hydrate VECTOR blobs).
     *
     * @return Attribute<?array<int, float>, array<int, float>|null>
     */
    protected function embedding(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): ?array {
                if ($this->pendingEmbedding !== null) {
                    return $this->pendingEmbedding;
                }

                if ($this->decodedEmbeddingLoaded) {
                    return $this->decodedEmbedding;
                }

                if (is_array($value)) {
                    /** @var array<int, float|int|string> $value */
                    return array_map(static fn (float|int|string $item): float => (float) $item, $value);
                }

                // JSON column (or VECTOR_TO_STRING already selected as text).
                if (is_string($value) && $value !== '' && str_starts_with(ltrim($value), '[')) {
                    $decoded = $this->decodeEmbeddingString($value);

                    if ($decoded !== null) {
                        return $decoded;
                    }
                }

                // Native VECTOR blobs from PDO are binary — never json_decode them.
                if (VectorStorage::usesNativeVector() && $this->exists) {
                    $this->decodedEmbedding = $this->fetchNativeEmbedding();
                    $this->decodedEmbeddingLoaded = true;

                    return $this->decodedEmbedding;
                }

                return null;
            },
            set: function (?array $value): ?string {
                $this->decodedEmbedding = $value;
                $this->decodedEmbeddingLoaded = true;

                if ($value === null) {
                    $this->pendingEmbedding = null;

                    return null;
                }

                if (VectorStorage::usesNativeVector()) {
                    // Defer STRING_TO_VECTOR until after the row has an id.
                    $this->pendingEmbedding = $value;

                    return null;
                }

                $this->pendingEmbedding = null;

                return json_encode($value, JSON_THROW_ON_ERROR);
            },
        );
    }

    /**
     * @return array<int, float>|null
     */
    private function fetchNativeEmbedding(): ?array
    {
        $row = DB::selectOne(
            'SELECT VECTOR_TO_STRING(embedding) AS embedding_string FROM products WHERE id = ?',
            [$this->id],
        );

        $raw = is_object($row) ? ($row->embedding_string ?? null) : null;

        return is_string($raw) && $raw !== ''
            ? $this->decodeEmbeddingString($raw)
            : null;
    }

    /**
     * Decode JSON or MySQL VECTOR_TO_STRING output (may use scientific notation).
     *
     * @return array<int, float>|null
     */
    private function decodeEmbeddingString(string $value): ?array
    {
        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            // VECTOR_TO_STRING can emit forms json_decode rejects; parse manually.
            if (! preg_match('/^\s*\[(.*)\]\s*$/s', $value, $matches)) {
                return null;
            }

            $parts = trim($matches[1]) === ''
                ? []
                : (preg_split('/\s*,\s*/', trim($matches[1])) ?: []);

            $decoded = [];

            foreach ($parts as $part) {
                if (! is_numeric($part)) {
                    return null;
                }

                $decoded[] = (float) $part;
            }
        }

        /** @var array<int, float|int|string> $decoded */
        return array_map(static fn (float|int|string $item): float => (float) $item, $decoded);
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
