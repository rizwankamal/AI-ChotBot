<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Shop;
use App\Support\ShopifyRequest;
use RuntimeException;
use Shopify\App\ShopifyApp;

class ShopifyClientService
{
    private ?Shop $shop = null;

    private const PRODUCTS_QUERY = <<<'GRAPHQL'
        query FetchProducts($first: Int!, $after: String) {
          products(first: $first, after: $after) {
            pageInfo {
              hasNextPage
              endCursor
            }
            edges {
              cursor
              node {
                id
                handle
                title
                descriptionHtml
                totalInventory
                productType
                featuredImage {
                  url
                }
                options {
                  name
                  values
                }
                variants(first: 50) {
                  edges {
                    node {
                      id
                      title
                      price
                      inventoryQuantity
                      availableForSale
                      selectedOptions {
                        name
                        value
                      }
                    }
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

    private const SHOP_POLICIES_QUERY = <<<'GRAPHQL'
        query FetchShopPolicies {
          shop {
            shopPolicies {
              id
              type
              title
              body
              url
            }
          }
        }
        GRAPHQL;

    private const PAGES_QUERY = <<<'GRAPHQL'
        query FetchPages($first: Int!, $after: String) {
          pages(first: $first, after: $after) {
            pageInfo {
              hasNextPage
              endCursor
            }
            edges {
              node {
                id
                title
                handle
                body
                isPublished
              }
            }
          }
        }
        GRAPHQL;

    private const ARTICLES_QUERY = <<<'GRAPHQL'
        query FetchArticles($first: Int!, $after: String) {
          articles(first: $first, after: $after) {
            pageInfo {
              hasNextPage
              endCursor
            }
            edges {
              node {
                id
                title
                handle
                body
                blog {
                  title
                  handle
                }
              }
            }
          }
        }
        GRAPHQL;

    private const ORDERS_LOOKUP_QUERY = <<<'GRAPHQL'
        query LookupOrders($query: String!, $first: Int!) {
          orders(first: $first, query: $query, sortKey: PROCESSED_AT, reverse: true) {
            edges {
              node {
                id
                name
                email
                phone
                createdAt
                processedAt
                displayFinancialStatus
                displayFulfillmentStatus
                cancelledAt
                cancelReason
                statusPageUrl
                totalPriceSet {
                  shopMoney {
                    amount
                    currencyCode
                  }
                }
                shippingAddress {
                  name
                  city
                  province
                  country
                  zip
                }
                fulfillments(first: 5) {
                  status
                  displayStatus
                  createdAt
                  updatedAt
                  estimatedDeliveryAt
                  deliveredAt
                  trackingInfo(first: 5) {
                    company
                    number
                    url
                  }
                }
                lineItems(first: 10) {
                  edges {
                    node {
                      title
                      quantity
                      sku
                      variantTitle
                    }
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

    public function __construct(
        private readonly ShopifyApp $shopifyApp,
    ) {}

    /**
     * Bind GraphQL calls to an installed shop's offline token.
     */
    public function forShop(Shop $shop): self
    {
        $clone = clone $this;
        $clone->shop = $shop;

        return $clone;
    }

    /**
     * Fetch a page of products from the Shopify Admin GraphQL API.
     *
     * @return array{
     *     products: list<array<string, mixed>>,
     *     pageInfo: array{hasNextPage: bool, endCursor: ?string}
     * }
     */
    public function fetchProducts(int $first = 50, ?string $cursor = null): array
    {
        $response = $this->graphql(self::PRODUCTS_QUERY, [
            'first' => $first,
            'after' => $cursor,
        ]);

        $connection = data_get($response, 'data.products');

        if (! is_array($connection)) {
            throw new RuntimeException('Shopify GraphQL response did not include products data.');
        }

        /** @var list<array<string, mixed>> $products */
        $products = collect(data_get($connection, 'edges', []))
            ->map(static fn (array $edge): array => $edge['node'] ?? [])
            ->filter()
            ->values()
            ->all();

        return [
            'products' => $products,
            'pageInfo' => [
                'hasNextPage' => (bool) data_get($connection, 'pageInfo.hasNextPage', false),
                'endCursor' => data_get($connection, 'pageInfo.endCursor'),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchShopPolicies(): array
    {
        $response = $this->graphql(self::SHOP_POLICIES_QUERY);
        $policies = data_get($response, 'data.shop.shopPolicies', []);

        if (! is_array($policies)) {
            throw new RuntimeException('Shopify GraphQL response did not include shopPolicies data.');
        }

        /** @var list<array<string, mixed>> $policies */
        return array_values(array_filter($policies, static fn (mixed $row): bool => is_array($row)));
    }

    /**
     * @return array{
     *     pages: list<array<string, mixed>>,
     *     pageInfo: array{hasNextPage: bool, endCursor: ?string}
     * }
     */
    public function fetchPages(int $first = 50, ?string $cursor = null): array
    {
        $response = $this->graphql(self::PAGES_QUERY, [
            'first' => $first,
            'after' => $cursor,
        ]);

        $connection = data_get($response, 'data.pages');

        if (! is_array($connection)) {
            throw new RuntimeException('Shopify GraphQL response did not include pages data.');
        }

        /** @var list<array<string, mixed>> $pages */
        $pages = collect(data_get($connection, 'edges', []))
            ->map(static fn (array $edge): array => $edge['node'] ?? [])
            ->filter()
            ->values()
            ->all();

        return [
            'pages' => $pages,
            'pageInfo' => [
                'hasNextPage' => (bool) data_get($connection, 'pageInfo.hasNextPage', false),
                'endCursor' => data_get($connection, 'pageInfo.endCursor'),
            ],
        ];
    }

    /**
     * @return array{
     *     articles: list<array<string, mixed>>,
     *     pageInfo: array{hasNextPage: bool, endCursor: ?string}
     * }
     */
    public function fetchArticles(int $first = 50, ?string $cursor = null): array
    {
        $response = $this->graphql(self::ARTICLES_QUERY, [
            'first' => $first,
            'after' => $cursor,
        ]);

        $connection = data_get($response, 'data.articles');

        if (! is_array($connection)) {
            throw new RuntimeException('Shopify GraphQL response did not include articles data.');
        }

        /** @var list<array<string, mixed>> $articles */
        $articles = collect(data_get($connection, 'edges', []))
            ->map(static fn (array $edge): array => $edge['node'] ?? [])
            ->filter()
            ->values()
            ->all();

        return [
            'articles' => $articles,
            'pageInfo' => [
                'hasNextPage' => (bool) data_get($connection, 'pageInfo.hasNextPage', false),
                'endCursor' => data_get($connection, 'pageInfo.endCursor'),
            ],
        ];
    }

    /**
     * Search orders via Admin GraphQL (requires read_orders).
     *
     * @return list<array<string, mixed>>
     */
    public function lookupOrders(string $query, int $first = 5): array
    {
        $response = $this->graphql(self::ORDERS_LOOKUP_QUERY, [
            'query' => $query,
            'first' => max(1, min($first, 10)),
        ]);

        $connection = data_get($response, 'data.orders');

        if (! is_array($connection)) {
            throw new RuntimeException('Shopify GraphQL response did not include orders data.');
        }

        /** @var list<array<string, mixed>> $orders */
        $orders = collect(data_get($connection, 'edges', []))
            ->map(static fn (array $edge): array => $edge['node'] ?? [])
            ->filter(static fn (mixed $node): bool => is_array($node) && $node !== [])
            ->values()
            ->all();

        return $orders;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{data: array<string, mixed>}
     */
    public function graphql(string $query, array $variables = []): array
    {
        return $this->graphqlWithRetry($query, $variables, retried: false);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{data: array<string, mixed>}
     */
    private function graphqlWithRetry(string $query, array $variables, bool $retried): array
    {
        [$subdomain, $token] = $this->credentials();
        $apiVersion = (string) config('services.shopify.api_version', '2026-04');

        $result = $this->shopifyApp->adminGraphQLRequest(
            $query,
            shop: $subdomain,
            accessToken: $token,
            apiVersion: $apiVersion,
            invalidTokenResponse: null,
            variables: $variables,
        );

        if (! $result->ok) {
            $code = (string) $result->log->code;
            $detail = (string) $result->log->detail;

            if (
                ! $retried
                && $this->shop instanceof Shop
                && ($code === 'unauthorized' || str_contains(strtolower($detail), 'invalid') || str_contains(strtolower($detail), 'revoked'))
            ) {
                app(ShopifyInstallService::class)->refreshToken($this->shop, force: true);
                $this->shop->refresh();

                return $this->graphqlWithRetry($query, $variables, retried: true);
            }

            throw new RuntimeException(sprintf(
                'Shopify GraphQL failed [%s]: %s',
                $code,
                $detail,
            ));
        }

        return [
            'data' => $result->data ?? [],
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function credentials(): array
    {
        if ($this->shop instanceof Shop) {
            if (! $this->shop->isInstalled()) {
                throw new RuntimeException("Shop [{$this->shop->shop_domain}] is not installed.");
            }

            return [
                ShopifyRequest::subdomain($this->shop->shop_domain),
                $this->shop->access_token,
            ];
        }

        $domain = config('services.shopify.shop_domain');
        $token = config('services.shopify.admin_access_token');

        if (! is_string($domain) || $domain === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException(
                'Shopify is not configured. Install the app or set SHOPIFY_SHOP_DOMAIN and SHOPIFY_ADMIN_ACCESS_TOKEN.'
            );
        }

        return [ShopifyRequest::subdomain($domain), $token];
    }
}
