<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'shopify' => [
        'api_key' => env('SHOPIFY_API_KEY'),
        'api_secret' => env('SHOPIFY_API_SECRET'),
        'scopes' => env(
            'SHOPIFY_SCOPES',
            'read_products,write_products,read_content,read_legal_policies,read_online_store_pages,read_orders'
        ),
        'shop_domain' => env('SHOPIFY_SHOP_DOMAIN'),
        'admin_access_token' => env('SHOPIFY_ADMIN_ACCESS_TOKEN'),
        'api_version' => env('SHOPIFY_API_VERSION', '2026-04'),
    ],


    'gemini' => [
        // gemini-1.5-flash is retired on many API keys; override via GEMINI_CHAT_MODEL.
        'chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-1.5-flash'),
        'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
    ],

    'openai' => [
        // Optional: free /v1/moderations when set (AI input guardrails).
        'api_key' => env('OPENAI_API_KEY'),
    ],

];
