<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],
        'media_quarantine' => [
            'driver' => 'local',
            'root' => storage_path('app/media-quarantine'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/media-quarantine',
            'serve' => true,
            'throw' => true,
            'report' => true,
        ],
        'media_public' => [
            'driver' => 'local',
            'root' => storage_path('app/public/media'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage/media',
            'visibility' => 'public',
            'throw' => true,
            'report' => true,
        ],
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],
    ],
    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
