<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'shop_domain',
        'access_token',
        'scopes',
        'token_expires_at',
        'installed_at',
        'uninstalled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasMany<ContentChunk, $this>
     */
    public function contentChunks(): HasMany
    {
        return $this->hasMany(ContentChunk::class);
    }

    /**
     * @return HasMany<SyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function getRouteKeyName(): string
    {
        return 'shop_domain';
    }

    public function isInstalled(): bool
    {
        return $this->uninstalled_at === null && $this->access_token !== '';
    }

    /**
     * Normalize myshopify domain (strip protocol / trailing slash).
     */
    protected function shopDomain(): Attribute
    {
        return Attribute::make(
            set: function (string $value): string {
                $value = preg_replace('#^https?://#', '', trim($value)) ?? $value;

                return rtrim(strtolower($value), '/');
            },
        );
    }
}
