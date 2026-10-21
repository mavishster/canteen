<?php

/**
 * Languages people can choose: code => the name written in that language.
 * The default for everyone who has not chosen is APP_LOCALE in .env (en or km).
 */
return [
    // The language for people who chose nothing. Kept apart from config('app.locale'), which Laravel overwrites with each request's language.
    'default' => env('APP_LOCALE', 'en'),

    'supported' => [
        'en' => 'English',
        'km' => 'ខ្មែរ',
    ],
];
