<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Shop;
use RuntimeException;

class ShopifyWebhookRegistrationService
{
    /**
     * Topics registered after a successful app install.
     *
     * @var list<string>
     */
    public const TOPICS = [
        'PRODUCTS_CREATE',
        'PRODUCTS_UPDATE',
        'PRODUCTS_DELETE',
        'APP_UNINSTALLED',
    ];

    public function __construct(
        private readonly ShopifyClientService $shopify,
    ) {}

    /**
     * Register required webhooks for the installed shop.
     *
     * @return list<array{topic: string, id: ?string}>
     */
    public function registerForShop(Shop $shop): array
    {
        $registered = [];
        $client = $this->shopify->forShop($shop);

        foreach (self::TOPICS as $topic) {
            $callbackUrl = $this->callbackUrlForTopic($topic);

            $response = $client->graphql(
                <<<'GRAPHQL'
                mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $callbackUrl: URL!) {
                  webhookSubscriptionCreate(
                    topic: $topic
                    webhookSubscription: {
                      format: JSON
                      callbackUrl: $callbackUrl
                    }
                  ) {
                    webhookSubscription {
                      id
                      topic
                      endpoint {
                        __typename
                        ... on WebhookHttpEndpoint {
                          callbackUrl
                        }
                      }
                    }
                    userErrors {
                      field
                      message
                    }
                  }
                }
                GRAPHQL,
                [
                    'topic' => $topic,
                    'callbackUrl' => $callbackUrl,
                ],
            );

            $errors = data_get($response, 'data.webhookSubscriptionCreate.userErrors', []);

            if (is_array($errors) && $errors !== []) {
                $alreadyExists = collect($errors)->contains(
                    static fn (array $error): bool => str_contains(
                        strtolower((string) ($error['message'] ?? '')),
                        'already been taken'
                    ) || str_contains(
                        strtolower((string) ($error['message'] ?? '')),
                        'address for this topic has already been taken'
                    )
                );

                if (! $alreadyExists) {
                    throw new RuntimeException(
                        "Failed to register {$topic} webhook: ".json_encode($errors, JSON_THROW_ON_ERROR)
                    );
                }
            }

            $registered[] = [
                'topic' => $topic,
                'id' => data_get($response, 'data.webhookSubscriptionCreate.webhookSubscription.id'),
            ];
        }

        return $registered;
    }

    private function callbackUrlForTopic(string $topic): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return match ($topic) {
            'PRODUCTS_CREATE' => "{$base}/api/webhooks/shopify/products/create",
            'PRODUCTS_UPDATE' => "{$base}/api/webhooks/shopify/products/update",
            'PRODUCTS_DELETE' => "{$base}/api/webhooks/shopify/products/delete",
            'APP_UNINSTALLED' => "{$base}/api/webhooks/shopify/app/uninstalled",
            default => throw new RuntimeException("Unsupported webhook topic [{$topic}]."),
        };
    }
}
