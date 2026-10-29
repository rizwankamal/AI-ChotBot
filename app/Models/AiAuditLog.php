<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAuditLog extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'trace_id',
        'shop_id',
        'operation',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'estimated_cost_usd',
        'latency_ms',
        'status',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'estimated_cost_usd' => 'decimal:8',
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
