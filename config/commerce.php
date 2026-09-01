<?php

return [
    'payment' => [
        'provider' => env('SOLE_PAYMENT_PROVIDER', 'disabled'),
        'callback_url' => env('SOLE_PAYMENT_CALLBACK_URL'),
        'timeout_seconds' => (int) env('SOLE_PAYMENT_TIMEOUT_SECONDS', 8),
        'zarinpal' => [
            'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
            'request_url' => 'https://api.zarinpal.com/pg/v4/payment/request.json',
            'verify_url' => 'https://api.zarinpal.com/pg/v4/payment/verify.json',
            'gateway_url' => 'https://www.zarinpal.com/pg/StartPay',
        ],
    ],
    'shipping' => [
        'provider' => env('SOLE_SHIPPING_PROVIDER', 'configured'),
        'webhook_secret' => env('SOLE_SHIPPING_WEBHOOK_SECRET'),
    ],
];
