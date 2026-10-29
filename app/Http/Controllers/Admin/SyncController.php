<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentChunk;
use App\Models\Product;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Services\SyncDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SyncController extends Controller
{
    public function dashboard(Shop $shop): View
    {
        abort_unless($shop->isInstalled(), 404);

        $latestByType = SyncRun::query()
            ->where('shop_id', $shop->id)
            ->orderByDesc('id')
            ->get()
            ->groupBy('type')
            ->map(static fn ($rows) => $rows->first());

        return view('admin.sync', [
            'shop' => $shop,
            'types' => [
                SyncRun::TYPE_PRODUCTS => [
                    'label' => 'Products',
                    'description' => 'Catalog titles, descriptions, prices, images, and embeddings.',
                    'count' => Product::query()->where('shop_id', $shop->id)->count(),
                ],
                SyncRun::TYPE_POLICIES => [
                    'label' => 'Policies',
                    'description' => 'Refund, shipping, privacy, and other shop policies.',
                    'count' => $this->documentCount($shop, 'policy'),
                ],
                SyncRun::TYPE_PAGES => [
                    'label' => 'Pages',
                    'description' => 'Published Online Store pages (About, FAQ, …).',
                    'count' => $this->documentCount($shop, 'page'),
                ],
                SyncRun::TYPE_BLOGS => [
                    'label' => 'Blogs',
                    'description' => 'Blog articles for content-aware answers.',
                    'count' => $this->documentCount($shop, 'article'),
                ],
            ],
            'latestByType' => $latestByType,
        ]);
    }

    public function start(Request $request, Shop $shop, string $type, SyncDispatchService $dispatcher): RedirectResponse|JsonResponse
    {
        abort_unless($shop->isInstalled(), 404);
        abort_unless(in_array($type, $dispatcher->types(), true), 404);

        $run = $dispatcher->dispatch($shop, $type);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'run' => $this->serializeRun($run),
            ]);
        }

        return redirect()
            ->route('admin.sync', $shop)
            ->with('status', ucfirst($type).' sync queued (#'.$run->id.').');
    }

    public function status(Shop $shop): JsonResponse
    {
        abort_unless($shop->isInstalled(), 404);

        $runs = SyncRun::query()
            ->where('shop_id', $shop->id)
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->groupBy('type')
            ->map(fn ($rows) => $this->serializeRun($rows->first()));

        return response()->json([
            'shop' => $shop->shop_domain,
            'latest' => $runs,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(SyncRun $run): array
    {
        return [
            'id' => $run->id,
            'type' => $run->type,
            'status' => $run->status,
            'stats' => $run->stats,
            'error_message' => $run->error_message,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
        ];
    }

    private function documentCount(Shop $shop, string $sourceType): int
    {
        return (int) ContentChunk::query()
            ->where('shop_id', $shop->id)
            ->where('source_type', $sourceType)
            ->selectRaw('count(distinct source_key) as aggregate')
            ->value('aggregate');
    }
}
