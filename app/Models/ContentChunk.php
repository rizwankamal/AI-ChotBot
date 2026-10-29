<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentChunk extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'shop_id',
        'source_type',
        'source_key',
        'title',
        'url',
        'chunk_index',
        'body',
        'embedding',
    ];

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
