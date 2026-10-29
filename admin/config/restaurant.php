<?php

return [
    // Times are stored in UTC; the admin panel shows them in this timezone.
    'timezone' => env('RESTAURANT_TIMEZONE', 'Asia/Kolkata'),

    // Order numbers look like CS1001, CS1002, ...
    'order_prefix' => env('ORDER_PREFIX', 'CS'),

    // Shared secret for Node <-> Laravel internal calls (same value in bot/.env).
    'internal_token' => env('WM_INTERNAL_TOKEN'),

    // Public HTTPS address of menu images, served by the bot through your tunnel:
    //   https://YOUR-TUNNEL/media/menu   (Meta downloads catalog photos from here)
    'public_media_url' => env('PUBLIC_MEDIA_URL'),

    // WhatsApp (Meta) catalog sync
    'catalog' => [
        'id' => env('META_CATALOG_ID'),
        'token' => env('META_CATALOG_TOKEN'),
        'graph_version' => env('GRAPH_API_VERSION', 'v26.0'),
        // Only change for local testing with a fake Graph API.
        'graph_base_url' => env('GRAPH_API_BASE_URL', 'https://graph.facebook.com'),
    ],
];
