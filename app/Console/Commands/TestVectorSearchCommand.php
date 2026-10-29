<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductEmbeddingService;
use App\Services\SemanticSearchService;
use Illuminate\Console\Command;

class TestVectorSearchCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:vector-search';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed sample products, generate embeddings, and run a semantic search demo';

    /**
     * Execute the console command.
     */
    public function handle(
        ProductEmbeddingService $embeddingService,
        SemanticSearchService $searchService,
    ): int {
        $this->info('Seeding sample products...');

        $products = $this->seedSampleProducts();

        $this->info('Generating embeddings via Gemini gemini-embedding-001 (768-dim)...');

        foreach ($products as $product) {
            $embeddingService->generateAndSave($product);
            $this->line("  • Embedded: {$product->title}");
        }

        $query = 'light clothes for beach vacation';
        $this->newLine();
        $this->info("Searching: \"{$query}\"");

        $results = $searchService->search($query, limit: 5);

        if ($results->isEmpty()) {
            $this->warn('No matching products found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Rank', 'Title', 'Category', 'Price', 'Inventory', 'Distance'],
            $results->values()->map(static function (Product $product, int $index): array {
                return [
                    $index + 1,
                    $product->title,
                    $product->category ?? '—',
                    number_format((float) $product->price, 2),
                    $product->inventory_count,
                    number_format((float) $product->getAttribute('distance'), 6),
                ];
            })->all(),
        );

        $this->comment('Lower distance = closer semantic match (cosine distance).');

        return self::SUCCESS;
    }

    /**
     * @return list<Product>
     */
    private function seedSampleProducts(): array
    {
        $samples = [
            [
                'shopify_id' => 'gid://shopify/Product/test-overcoat',
                'title' => 'Overcoat',
                'description' => 'Heavy wool winter overcoat for cold weather and formal occasions.',
                'price' => '189.00',
                'inventory_count' => 8,
                'category' => 'Outerwear',
            ],
            [
                'shopify_id' => 'gid://shopify/Product/test-linen-shirt',
                'title' => 'Linen Shirt',
                'description' => 'Breathable light linen shirt ideal for hot summer days and beach trips.',
                'price' => '49.00',
                'inventory_count' => 20,
                'category' => 'Shirts',
            ],
            [
                'shopify_id' => 'gid://shopify/Product/test-trail-shoes',
                'title' => 'Trail Running Shoes',
                'description' => 'Rugged trail running shoes with grip for rocky mountain paths.',
                'price' => '129.99',
                'inventory_count' => 12,
                'category' => 'Footwear',
            ],
        ];

        $products = [];

        foreach ($samples as $sample) {
            $products[] = Product::query()->updateOrCreate(
                ['shopify_id' => $sample['shopify_id']],
                $sample,
            );
        }

        return $products;
    }
}
