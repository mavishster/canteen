<?php

// How messages reach parents. 'log' writes them to the log (development only: it contains names and balances),
// 'none' sends nothing, 'fake' is for tests. Real drivers (push, SMS provider) are added later.
return [
    'push' => env('PUSH_DRIVER', env('APP_ENV') === 'production' ? 'none' : 'log'),
    'sms' => env('SMS_DRIVER', env('APP_ENV') === 'production' ? 'none' : 'log'),
];
