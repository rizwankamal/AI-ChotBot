<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncRun;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Services\ShopifyClientService;
use App\Services\ShopifyContentSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncShopArticlesJob implements ShouldQueue
{
    use Queueable;
    use TracksSyncRun;

    public int $tries = 3;

    public function __construct(
        public Shop $shop,
        public SyncRun $syncRun,
    ) {
        $this->onQueue((string) config('ai.queue', 'ai-processing'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        ShopifyClientService $shopify,
        ShopifyContentSyncService $contentSync,
    ): void {
        if (! $this->shop->isInstalled()) {
            $this->syncRun->markFailed('Shop is not installed.');

            return;
        }

        $this->withSyncRun($this->syncRun, function () use ($shopify, $contentSync): array {
            $client = $shopify->forShop($this->shop);
            $cursor = null;
            $liveKeys = [];
            $chunkCount = 0;
            $documentCount = 0;

            do {
                $result = $client->fetchArticles(50, $cursor);

                foreach ($result['articles'] as $article) {
                    $sourceKey = (string) ($article['id'] ?? '');
                    $title = (string) ($article['title'] ?? '');
                    $handle = (string) ($article['handle'] ?? '');
                    $body = (string) ($article['body'] ?? '');
                    $blogHandle = (string) data_get($article, 'blog.handle', '');
                    $blogTitle = (string) data_get($article, 'blog.title', '');

                    if ($sourceKey === '' || $body === '') {
                        continue;
                    }

                    $liveKeys[] = $sourceKey;
                    $displayTitle = $title;
                    if ($blogTitle !== '') {
                        $displayTitle = $blogTitle.': '.$title;
                    }

                    $url = null;
                    if ($blogHandle !== '' && $handle !== '') {
                        $url = 'https://'.$this->shop->shop_domain.'/blogs/'.$blogHandle.'/'.$handle;
                    }

                    $upserted = $contentSync->upsertDocument(
                        shop: $this->shop,
                        sourceType: ShopifyContentSyncService::SOURCE_ARTICLE,
                        sourceKey: $sourceKey,
                        title: $displayTitle !== '' ? $displayTitle : $handle,
                        bodyHtml: $body,
                        url: $url,
                    );
                    $chunkCount += $upserted['chunks'];
                    $documentCount++;
                }

                $cursor = $result['pageInfo']['hasNextPage'] ? $result['pageInfo']['endCursor'] : null;
            } while ($cursor !== null);

            $pruned = $contentSync->pruneMissing(
                $this->shop,
                ShopifyContentSyncService::SOURCE_ARTICLE,
                $liveKeys,
            );

            return [
                'documents' => $documentCount,
                'chunks' => $chunkCount,
                'pruned' => $pruned,
            ];
        });
    }
}
