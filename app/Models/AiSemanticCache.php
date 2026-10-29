<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSemanticCache extends Model
{
    protected $table = 'ai_semantic_cache';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'shop_id',
        'query_hash',
        'query_text',
        'response_text',
        'query_embedding',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
