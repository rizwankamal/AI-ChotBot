<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\SyncRun;
use App\Services\Ai\ChatCacheInvalidator;
use Throwable;

trait TracksSyncRun
{
    protected function withSyncRun(SyncRun $run, callable $callback): void
    {
        $run->markRunning();

        try {
            /** @var array<string, mixed> $stats */
            $stats = $callback();
            $run->markCompleted(is_array($stats) ? $stats : []);

            // Old "no policy found" answers must not survive a successful sync.
            if ($run->shop !== null) {
                app(ChatCacheInvalidator::class)->forShop($run->shop);
            } elseif ($run->shop_id) {
                $run->loadMissing('shop');
                if ($run->shop !== null) {
                    app(ChatCacheInvalidator::class)->forShop($run->shop);
                }
            }
        } catch (Throwable $exception) {
            $run->markFailed($exception->getMessage());

            throw $exception;
        }
    }
}
