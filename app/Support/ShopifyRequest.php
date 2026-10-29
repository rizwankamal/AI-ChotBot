<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Shopify\App\Types\ResultForReq;
use Symfony\Component\HttpFoundation\Response;

final class ShopifyRequest
{
    /**
     * Convert a Laravel request into the shape shopify/shopify-app-php expects.
     *
     * @return array{method: string, headers: array<string, string>, url: string, body: string}
     */
    public static function fromLaravel(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        return [
            'method' => $request->method(),
            'headers' => $headers,
            'url' => $request->fullUrl(),
            'body' => $request->getContent(),
        ];
    }

    /**
     * Convert a package verify/GraphQL failure result into a Laravel response.
     */
    public static function toResponse(ResultForReq $result): Response
    {
        return response($result->response->body, $result->response->status)
            ->withHeaders((array) $result->response->headers);
    }

    /**
     * Normalize a shop domain to the subdomain form expected by the package ("demo-store").
     */
    public static function subdomain(string $shop): string
    {
        $shop = strtolower(trim($shop));
        $shop = preg_replace('#^https?://#', '', $shop) ?? $shop;
        $shop = rtrim($shop, '/');

        return str_replace('.myshopify.com', '', $shop);
    }

    /**
     * Expand a subdomain to a full myshopify host.
     */
    public static function fullDomain(string $shop): string
    {
        $subdomain = self::subdomain($shop);

        return $subdomain.'.myshopify.com';
    }
}
