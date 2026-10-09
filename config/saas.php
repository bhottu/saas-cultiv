<?php

// SaaS infrastructure configuration. Plans/limits are DB-driven; this holds behavior knobs only.
return [
    'trial_days' => env('SAAS_TRIAL_DAYS', 14),

    /*
    | Which payment gateway NEW checkouts use when the admin has not picked one
    | (payment_settings row absent or unreadable). The stored choice lives in the
    | payment_settings table; this env value is only the boot-time fallback and the
    | documented default, so an un-migrated database still behaves exactly like the
    | single-gateway release did: QRIS.PW.
    */
    'default_payment_gateway' => env('PAYMENT_GATEWAY', 'qrispw'),

    // After subscription expiry, tenant data is retained this long before purge jobs run.
    'data_retention_days' => env('SAAS_DATA_RETENTION_DAYS', 30),

    // Subscription states considered "active" for paid feature access.
    'active_states' => ['trialing', 'active', 'past_due'],

    'usage' => [
        'max_workspaces' => ['period' => 'forever', 'fallback' => 1],
        'max_users'     => ['period' => 'forever', 'fallback' => 1],
        'max_products'  => ['period' => 'forever', 'fallback' => 100],
        'max_customers' => ['period' => 'forever', 'fallback' => null],
        'storage_mb'    => ['period' => 'forever', 'fallback' => 100],
        'api_calls'     => ['period' => 'month',   'fallback' => 1000],
        // Per-workspace API requests/minute for a plan that does not store the key.
        // The plan rows carry their own value (Starter 60, Pro 300, Business 1000);
        // this is only the fallback for a legacy plan created before the key existed.
        'api_rate_limit' => ['period' => 'forever', 'fallback' => 60],
        'ai_messages'   => ['period' => 'month',   'fallback' => null],
    ],

    /*
    | Entitlement keys a Plan row may carry.
    |
    | The authoritative list of everything Plan::limit() (numeric ceilings) and
    | Plan::allows() (on/off capabilities) can read. The admin plan editor validates
    | submitted keys against this, so a typo is rejected or dropped instead of being
    | written into a plan where nothing would ever read it — and so a new capability is
    | editable from /admin/plans the moment it is added here.
    |
    | Existing plan rows are never migrated by this list; it only governs what may be
    | EDITED. A plan created before a key existed simply does not have that key, and
    | Plan::allows() reads a missing key as false.
    */
    'plan_entitlements' => [
        // Numeric ceilings. A null value means "unlimited" and is meaningful, not absent.
        'max_workspaces',
        'max_users',
        'max_products',
        'max_customers',
        'max_storage_mb',
        'max_api_calls',
        // API requests per minute, per workspace (rate limit vs. the monthly quota
        // above — two different ceilings, both plan-configurable).
        'api_rate_limit',
        'max_ai_messages',

        // Capability switches read through Plan::allows().
        'basic_sales',
        'basic_stock',
        'basic_purchases',
        'basic_reports',
        'advanced_reports',
        'advanced_permissions',
        'advanced_analytics',
        'api_access',
        'audit_log',
        'cultiv_ai',
    ],

    // Feature switches that remove a whole surface by not registering its routes.
    // Read at route-registration time, so `php artisan optimize:clear` / route cache
    // must be re-run after changing the env value.
    'features' => [
        // The tenant file manager (/files). Disabled at the routing layer, not by hiding
        // links: the controller, model, table and storage disk all stay in place so the
        // feature can be switched back on. Internal uploads that other features rely on —
        // notably the workspace branding logo — use their own routes and are unaffected.
        'files_manager' => env('FILES_MANAGER_ENABLED', false),
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