<?php

return [
    // 'database' = the real SIS (token check over its API, parent data from its read-only database).
    // 'fake' is for tests.
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

    // The SIS API that tells us who owns a login token. Only the user's id is read from the answer.
    'api' => [
        'base_url' => env('SIS_API_URL'),                              // must be https:// in production
        'user_path' => env('SIS_API_USER_PATH', '/api/user'),
        'wrapper' => env('SIS_API_USER_WRAPPER'),                      // e.g. "data" when the answer looks like {"data": {"id": 5}}
        'id_field' => env('SIS_API_USER_ID_FIELD', 'id'),
        'timeout' => (int) env('SIS_API_TIMEOUT', 5),
    ],

    // Parents who signed in through the SIS: how often their children are re-checked, and how long
    // the saved links keep working if the SIS cannot be reached.
    'link_cache_minutes' => (int) env('SIS_LINK_CACHE_MINUTES', 10),
    'link_grace_minutes' => (int) env('SIS_LINK_GRACE_MINUTES', 60),

    // The email + password parent login from Step 15. Set to false in production once the SIS login is live.
    'allow_local_parent_login' => (bool) env('SIS_ALLOW_LOCAL_PARENT_LOGIN', true),
];
