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

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
        'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
    ],

    // QRIS.PW payment gateway. Secrets stay in .env — never in the browser.
    'qrispw' => [
        'api_key' => env('QRISPW_API_KEY'),
        'api_secret' => env('QRISPW_API_SECRET'),
        'webhook_secret' => env('QRISPW_WEBHOOK_SECRET'),
        'endpoints' => [
            'create' => env('QRISPW_BASE_URL', 'https://qris.pw/api').'/create-payment.php',
            'status' => env('QRISPW_BASE_URL', 'https://qris.pw/api').'/check-payment.php',
        ],
    ],

];
