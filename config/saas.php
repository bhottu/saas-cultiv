<?php

// SaaS infrastructure configuration. Plans/limits are DB-driven; this holds behavior knobs only.
return [
    'trial_days' => env('SAAS_TRIAL_DAYS', 14),

    // After subscription expiry, tenant data is retained this long before purge jobs run.
    'data_retention_days' => env('SAAS_DATA_RETENTION_DAYS', 30),

    // Subscription states considered "active" for paid feature access.
    'active_states' => ['trialing', 'active', 'past_due'],

    'usage' => [
        'storage_mb' => ['period' => 'forever', 'fallback' => 100],
        'api_calls'  => ['period' => 'month',   'fallback' => 1000],
    ],

    // Secure file uploads (§18/§19). Never trust the client filename/extension alone.
    'uploads' => [
        'disk' => env('SAAS_UPLOAD_DISK', env('FILESYSTEM_DISK', 'local')),
        // Server-side whitelist; the MIME type is verified from content, not the extension.
        'allowed_mimes' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
            'application/pdf',
            'text/plain', 'text/csv',
            'application/zip',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'max_size_kb' => env('SAAS_UPLOAD_MAX_KB', 10240), // 10 MB
    ],
];