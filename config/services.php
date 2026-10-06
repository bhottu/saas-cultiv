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

    // Google sign-in (OAuth via Laravel Socialite).
    //
    // Credentials live in .env only — never in this file. `redirect` must match an
    // Authorized redirect URI registered in the Google Cloud console EXACTLY, or
    // Google rejects the exchange with redirect_uri_mismatch.
    //
    // Only identity scopes are requested (see GoogleAuthController): openid, profile,
    // email. Cultiv One never asks for Gmail, Drive, Calendar or contacts.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/auth/google/callback'),
    ],

    // QRIS.PW payment gateway. Secrets stay in .env — never in the browser.
    'qrispw' => [
        'api_key' => env('QRISPW_API_KEY'),
        'api_secret' => env('QRISPW_API_SECRET'),
        'webhook_secret' => env('QRISPW_WEBHOOK_SECRET'),

        /*
         | The gateway rejects any amount below this with a 400 "Minimum amount is
         | Rp 1,000". Checking it locally turns a confusing provider refusal — which the
         | customer is wrongly told to "try again" for something retrying cannot fix —
         | into a precise internal error naming the plan and its price. Override only if
         | the provider changes its floor.
         */
        'min_amount' => (int) env('QRISPW_MIN_AMOUNT', 1000),

        'endpoints' => [
            'create' => env('QRISPW_BASE_URL', 'https://qris.pw/api').'/create-payment.php',
            'status' => env('QRISPW_BASE_URL', 'https://qris.pw/api').'/check-payment.php',
        ],
    ],

    /*
    | Kasera Pay gateway (pay.kasera.id). Bearer auth with kp_test / kp_live keys.
    | Secrets stay in .env — never in the browser, never in a log line.
    |
    | POST /v1/transactions creates a transaction (Idempotency-Key header dedupes
    | retries of the same order) and answers with `id`, `checkout_url` and an
    | optional QR string; GET /v1/transactions/{id} is the server-side fallback
    | used by reconcile when a webhook is delayed or lost.
    */
    'kasera' => [
        'api_key' => env('KASERA_API_KEY'),
        'webhook_secret' => env('KASERA_WEBHOOK_SECRET'),

        // The same QRIS rail floor as QRIS.PW: below Rp 1,000 a QRIS transaction
        // cannot exist, so a plan priced under it is refused locally with a precise
        // operator log instead of a confusing provider 400.
        'min_amount' => (int) env('KASERA_MIN_AMOUNT', 1000),

        'endpoints' => [
            'create' => env('KASERA_BASE_URL', 'https://pay.kasera.id').'/v1/transactions',
            // Retrieve = create . '/' . transaction id (see KaseraClient::checkStatus).
        ],
    ],

];
