<?php

return [
    // 'database' reads the SIS MySQL database (read-only user). 'fake' is for tests.
    'driver' => env('SIS_DRIVER', 'database'),

    // How the SIS writes a card number compared with what the canteen reader types for the same card:
    //   same                     identical text (e.g. both print 0001035627)
    //   decimal_to_hex           SIS has decimal, the canteen reader prints hex (1035627 -> 000FCD6B)
    //   decimal_to_hex_reversed  same, with the bytes in the opposite order (-> 6BCD0F00)
    // Check with a real card: run `php artisan sis:card-formats <SIS rfid>` and compare with the Bind card box.
    'card_format' => env('SIS_CARD_FORMAT', 'same'),

    'database' => [
        'driver' => 'mysql',
        'host' => env('SIS_DB_HOST', '127.0.0.1'),
        'port' => env('SIS_DB_PORT', 3306),
        'database' => env('SIS_DB_DATABASE'),
        'username' => env('SIS_DB_USERNAME'),
        'password' => env('SIS_DB_PASSWORD'),
        'ssl_ca' => env('SIS_DB_SSL_CA'),   // path to the CA certificate when the SIS is on another server
    ],
];
