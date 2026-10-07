<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Built-in module definitions
    |--------------------------------------------------------------------------
    |
    | Seeded by ModuleSeeder into the `modules` table on first run.
    | Changes here update or seed the platform catalogue via:
    |   php artisan db:seed --class=ModuleSeeder
    |
    | Pricing is in WHOLE RUPIAH (SaaS billing convention), rendered via
    | Money::formatRupiah().
    */
    'registry' => [
        'pos' => [
            'key'            => 'pos',
            'name'           => 'Point of Sale (POS)',
            'slug'           => 'pos',
            'description'    => 'Fast cashier terminal for in-store sales, barcode scanning, cart calculation, and direct receipt printing.',
            'icon'           => 'shopping-cart',
            'version'        => '1.0.0',
            'category'       => 'operations',
            'is_core'        => false,
            'is_paid'        => false,
            'price_monthly'  => 0,
            'price_yearly'   => 0,
            'currency'       => 'IDR',
            'min_plan'       => null,               // Available to every workspace (Free tier included)
            'route'          => 'pos.index',
            'permission'     => 'sales.create',     // Cashier/Staff/Admin can checkout
            'sidebar_group'  => 'Sales',
            'sort_order'     => 10,
        ],
        'ai_agent' => [
            'key'            => 'ai_agent',
            'name'           => 'AI Assistant Telegram',
            'slug'           => 'ai-agent',
            'description'    => 'Manage your workspace through Telegram with help from AI.',
            'icon'           => 'sparkles',
            'version'        => '1.0.0',
            'category'       => 'operations',
            'is_core'        => false,
            'is_paid'        => false,
            'price_monthly'  => 0,
            'price_yearly'   => 0,
            'currency'       => 'IDR',
            'min_plan'       => null,
            'route'          => 'ai.channel.edit',
            'permission'     => null,
            'sidebar_group'  => null,
            'sort_order'     => 20,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Manifest overrides (code-side routing & UI metadata)
    |--------------------------------------------------------------------------
    */
    'manifests' => [
        'pos' => [
            'name'          => 'POS',
            'route'         => 'pos.index',
            'active'        => 'pos.*',
            'icon'          => 'shopping-cart',
            'permission'    => 'sales.create',
            'sidebar_group' => 'Sales',
        ],
        'ai_agent' => [
            'name'         => 'AI Assistant Telegram',
            'route'        => 'ai.channel.edit',
            'plan_feature' => 'cultiv_ai',
            'icon'         => 'sparkles',
        ],
    ],
];
