<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRun extends Model
{
    public const TYPE_PRODUCTS = 'products';

    public const TYPE_POLICIES = 'policies';

    public const TYPE_PAGES = 'pages';

    public const TYPE_BLOGS = 'blogs';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'shop_id',
        'type',
        'status',
        'stats',
        'error_message',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function markRunning(): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
            'error_message' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    public function markCompleted(array $stats = []): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'stats' => $stats,
            'finished_at' => now(),
            'error_message' => null,
        ]);
    }

    public function markFailed(string $message, array $stats = []): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'stats' => $stats === [] ? $this->stats : $stats,
            'error_message' => mb_substr($message, 0, 2000),
            'finished_at' => now(),
        ]);
    }
}
