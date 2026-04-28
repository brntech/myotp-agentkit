<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'myotp' => [
        // Read once at config-cache time. Do not call env() outside of config
        // files in Laravel -- env() returns null after `php artisan config:cache`.
        'key' => env('MYOTP_API_KEY'),
        'base_url' => env('MYOTP_BASE_URL', 'https://api.myotp.app'),
    ],
];
