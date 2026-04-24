<?php

return [
    'gateways' => [
        'airtel_money' => [
            'base_url' => env('PAYMENT_AIRTEL_BASE_URL'),
            'api_key' => env('PAYMENT_AIRTEL_API_KEY'),
            'api_secret' => env('PAYMENT_AIRTEL_API_SECRET'),
            'webhook_secret' => env('PAYMENT_AIRTEL_WEBHOOK_SECRET'),
        ],
        'moov_money' => [
            'base_url' => env('PAYMENT_MOOV_BASE_URL'),
            'api_key' => env('PAYMENT_MOOV_API_KEY'),
            'api_secret' => env('PAYMENT_MOOV_API_SECRET'),
            'webhook_secret' => env('PAYMENT_MOOV_WEBHOOK_SECRET'),
        ],
        'visa_mastercard' => [
            'base_url' => env('PAYMENT_CARD_BASE_URL'),
            'api_key' => env('PAYMENT_CARD_API_KEY'),
            'api_secret' => env('PAYMENT_CARD_API_SECRET'),
            'webhook_secret' => env('PAYMENT_CARD_WEBHOOK_SECRET'),
        ],
    ],
];
