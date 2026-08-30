<?php

return [
    'quarantine_disk' => env('SOLE_MEDIA_QUARANTINE_DISK', 'media_quarantine'),
    'delivery_disk' => env('SOLE_MEDIA_DELIVERY_DISK', 'media_public'),
    'upload_ttl_minutes' => (int) env('SOLE_MEDIA_UPLOAD_TTL_MINUTES', 10),
    'max_bytes' => (int) env('SOLE_MEDIA_MAX_BYTES', 10485760),
    'max_width' => (int) env('SOLE_MEDIA_MAX_WIDTH', 8000),
    'max_height' => (int) env('SOLE_MEDIA_MAX_HEIGHT', 8000),
    'max_pixels' => (int) env('SOLE_MEDIA_MAX_PIXELS', 40000000),
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    'recipe_version' => 1,
    'recipes' => [
        'thumb' => ['width' => 320, 'height' => 320, 'fit' => 'cover', 'quality' => 80],
        'card' => ['width' => 640, 'height' => 800, 'fit' => 'cover', 'quality' => 82],
        'pdp' => ['width' => 1200, 'height' => 1200, 'fit' => 'contain', 'quality' => 84],
    ],
];
