<?php

return [
    // 'fake' works only outside production. Set PAYMENT_GATEWAY=aba once the ABA driver is finished.
    'default' => env('PAYMENT_GATEWAY', 'fake'),

    'aba' => [
        'merchant_id' => env('ABA_MERCHANT_ID'),
        'api_key' => env('ABA_API_KEY'),
        'base_url' => env('ABA_BASE_URL'),   // sandbox first, production after go-live
    ],
];
