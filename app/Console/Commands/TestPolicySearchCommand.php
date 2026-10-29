<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\ContentSearchService;
use App\Services\StoreAssistantService;
use Illuminate\Console\Command;
use Throwable;

class TestPolicySearchCommand extends Command
{
    protected $signature = 'shoppulse:test-policy-search
                            {shop=staging-ai-ppb.myshopify.com : Shop domain}
                            {--q=Can I return a product bought 10 days ago? : Customer question}';

    protected $description = 'Test return/refund policy retrieval + short assistant answer';

    public function handle(ContentSearchService $search, StoreAssistantService $assistant): int
    {
        $domain = (string) $this->argument('shop');
        $question = (string) $this->option('q');

        $shop = Shop::query()->where('shop_domain', $domain)->first();

        if ($shop === null) {
            $this->error("Shop [{$domain}] not found.");

            return self::FAILURE;
        }

        $this->info("Shop #{$shop->id} {$shop->shop_domain}");
        $this->info('Intent: '.($search->detectIntent($question) ?? 'null'));

        $hits = $search->search($question, limit: 5, shop: $shop, sourceTypes: ['policy']);

        if ($hits->isEmpty()) {
            $this->error('No policy hits.');

            return self::FAILURE;
        }

        $this->table(
            ['#', 'Title', 'Distance', 'Excerpt'],
            $hits->values()->map(static function ($chunk, int $i): array {
                return [
                    $i + 1,
                    $chunk->title,
                    (string) $chunk->getAttribute('distance'),
                    mb_substr(preg_replace('/\s+/', ' ', (string) $chunk->body) ?? '', 0, 80),
                ];
            })->all(),
        );

        $top = $hits->first();
        $ok = is_string($top?->title) && preg_match('/refund|return/i', $top->title);

        if (! $ok) {
            $this->error('FAIL: top hit is not a refund/return policy (got: '.($top?->title ?? 'null').')');

            return self::FAILURE;
        }

        $this->info('PASS: top hit is refund/return policy.');

        try {
            $reply = $assistant->forShop($shop)->handleCustomerQuery($question);
            $this->newLine();
            $this->line('Assistant: '.$reply['text']);
        } catch (Throwable $exception) {
            $this->warn('Assistant call skipped: '.$exception->getMessage());
        }

        return self::SUCCESS;
    }
}
