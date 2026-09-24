<?php

// Fine-grained business permissions on top of the existing RBAC registry.
// Maps CRUD/document actions to registry verbs (view_records/create_records/update_records/delete_records/view_reports/manage_settings)
// so we reuse the existing Gate infrastructure instead of a parallel permission system.
// Owner/Admin bypass is handled by the existing Gate::before + role registry; this helper is the
// single place business controllers ask "can this user do this business action?".

return [
    'domains' => [
        'products', 'categories', 'brands', 'warehouses',
        'inventory', 'stock',
        'customers', 'suppliers',
        'purchases', 'sales',
        'invoices',        // business invoices (sales documents)
        'payments',        // business sale payments
        'expenses',
        'reports',
        'business_settings',
    ],

    // Action -> registry permission verb mapping.
    'action_map' => [
        'categories.view'         => 'view_records',
        'categories.create'       => 'create_records',
        'categories.update'       => 'update_records',
        'categories.delete'       => 'delete_records',

        'brands.view'             => 'view_records',
        'brands.create'           => 'create_records',
        'brands.update'           => 'update_records',
        'brands.delete'           => 'delete_records',

        'warehouses.view'         => 'view_records',
        'warehouses.create'       => 'create_records',
        'warehouses.update'       => 'update_records',
        'warehouses.delete'       => 'delete_records',

        'products.view'           => 'view_records',
        'products.create'         => 'create_records',
        'products.update'         => 'update_records',
        'products.delete'         => 'delete_records',
        'products.export'         => 'view_records',

        'inventory.view'          => 'view_records',
        'stock.view'              => 'view_records',
        'stock.movements.view'    => 'view_records',
        'stock.adjust'            => 'update_records',
        'stock.transfer'          => 'update_records',

        'customers.view'          => 'view_records',
        'customers.create'        => 'create_records',
        'customers.update'        => 'update_records',
        'customers.delete'        => 'delete_records',
        'customers.export'        => 'view_records',

        'suppliers.view'          => 'view_records',
        'suppliers.create'        => 'create_records',
        'suppliers.update'        => 'update_records',
        'suppliers.delete'        => 'delete_records',
        'suppliers.export'        => 'view_records',

        'purchases.view'          => 'view_records',
        'purchases.create'        => 'create_records',
        'purchases.receive'       => 'update_records',
        'purchases.cancel'        => 'update_records',
        'purchases.export'        => 'view_records',

        'sales.view'              => 'view_records',
        'sales.create'            => 'create_records',
        'sales.complete'          => 'update_records',
        'sales.cancel'            => 'update_records',
        'sales.refund'            => 'update_records',
        'sales.return'            => 'update_records',
        'sales.export'            => 'view_records',

        'invoices.view'           => 'view_records',
        'invoices.pdf'            => 'view_records',
        'payments.view'           => 'view_records',
        'payments.create'         => 'create_records',

        'expenses.view'           => 'view_records',
        'expenses.create'         => 'create_records',
        'expenses.delete'         => 'delete_records',

        'reports.view'            => 'view_reports',
        'reports.export'          => 'view_reports',

        'business_settings.manage'=> 'manage_settings',
    ],

    // Sales module options — the single source of truth for the UI selects AND the
    // server-side validation (Rule::in), so a channel/method can never drift between them.
    'sales' => [
        'channels' => [
            'pos'         => 'POS',
            'online'      => 'Online Store',
            'whatsapp'    => 'WhatsApp',
            'marketplace' => 'Marketplace',
            'manual'      => 'Manual',
        ],

        'payment_methods' => [
            'cash'          => 'Cash',
            'bank_transfer' => 'Bank Transfer',
            'qris'          => 'QRIS',
            'debit_card'    => 'Debit Card',
            'credit_card'   => 'Credit Card',
            'ewallet'       => 'E-Wallet',
            'other'         => 'Other',
        ],

        // Default tax rate for new sales. A tenant can override it with
        // tenants.settings.tax_percent — the rate is stored per sale, never hard-coded.
        'tax_percent' => env('BUSINESS_TAX_PERCENT', 0),

        // Optional fields: only shown when the business actually needs them.
        'shipping_enabled' => env('BUSINESS_SALES_SHIPPING', false),

        // Inventory policy for sales. false = never sell more than is in stock.
        'allow_negative_stock' => env('BUSINESS_ALLOW_NEGATIVE_STOCK', false),

        'default_channel'        => 'pos',
        'default_payment_method' => 'cash',
    ],
];