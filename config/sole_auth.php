<?php

return [
    'frontend_url' => env('SOLE_FRONTEND_URL', 'http://localhost:5173'),

    'google' => [
        'enabled' => env('SOLE_AUTH_GOOGLE_ENABLED', false),
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', env('APP_URL', 'http://localhost:8000').'/auth/google/callback'),
    ],

    'otp' => [
        'enabled' => env('SOLE_AUTH_OTP_ENABLED', false),
        'ttl_seconds' => (int) env('SOLE_AUTH_OTP_TTL_SECONDS', 300),
        'max_attempts' => (int) env('SOLE_AUTH_OTP_MAX_ATTEMPTS', 5),
        'resend_seconds' => (int) env('SOLE_AUTH_OTP_RESEND_SECONDS', 60),
        'request_limit' => (int) env('SOLE_AUTH_OTP_REQUEST_LIMIT', 5),
        'request_decay_seconds' => (int) env('SOLE_AUTH_OTP_REQUEST_DECAY_SECONDS', 600),
        'kavenegar' => [
            'api_key' => env('KAVENEGAR_API_KEY'),
            'verify_template' => env('KAVENEGAR_VERIFY_TEMPLATE'),
            'timeout_seconds' => (int) env('KAVENEGAR_TIMEOUT_SECONDS', 10),
        ],
    ],
];
