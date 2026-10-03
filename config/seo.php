<?php

/*
|--------------------------------------------------------------------------
| SEO defaults
|--------------------------------------------------------------------------
|
| Every page inherits from here. A page only states what makes it DIFFERENT —
| a title, a description, an image — and everything else falls back to these
| values, so the metadata can never drift between pages or be duplicated by hand.
|
| `url` is deliberately NOT read from the request. Deriving the canonical from the
| incoming Host header would let a visitor dictate what Google is told the address
| of the site is, and would publish http://localhost in a canonical tag on every
| local request. One configured origin is used everywhere instead.
|
*/

return [
    'site_name' => env('SEO_SITE_NAME', 'Cultiv'),
    'url' => rtrim((string) env('SEO_URL', 'https://cultiv.id'), '/'),
    'locale' => 'id_ID',
    'alternate_locales' => ['id_ID'],

    // Format for every page except the homepage, which uses default_title as-is.
    'title_separator' => ' — ',

    'default_title' => 'Cultiv — SaaS untuk Mengelola Bisnis dalam Satu Workspace',
    'default_description' => 'Kelola produk, inventaris, pembelian, penjualan, pelanggan, dan laporan bisnis dalam satu workspace dengan Cultiv.',

    /*
    | Title per route, as "[Page Title] — Cultiv".
    |
    | Keyed by route name rather than passed through a view variable on purpose. A
    | controller's $pageTitle is only visible inside the view that declares it — it
    | never reaches layouts/guest.blade.php or layouts/app.blade.php, because those are
    | class components and the parent's local variables are not forwarded into them.
    | The route map sidesteps that entirely, and keeps every title in one file instead
    | of spread across controllers that each have to remember.
    |
    | Any route missing here falls back to default_title, so add an entry whenever a
    | page is added; otherwise several pages would silently share one title.
    */
    'route_titles' => [
        'home' => null, // null: the homepage uses default_title verbatim.
        'login' => 'Log in',
        'register' => 'Register',
        'password.request' => 'Reset your password',
        'password.reset' => 'Choose a new password',
        'verification.notice' => 'Verify your email',
        'dashboard' => 'Dashboard',
        'products.index' => 'Products',
        'products.show' => 'Product detail',
        'products.create' => 'Add product',
        'products.edit' => 'Edit product',
        'sales.index' => 'Sales',
        'sales.create' => 'New sale',
        'stock.index' => 'Stock',
        'stock.movements' => 'Stock movements',
        'stock.low' => 'Low stock',
        'billing.index' => 'Billing',
        'customers.index' => 'Customers',
        'suppliers.index' => 'Suppliers',
        'categories.index' => 'Categories',
        'brands.index' => 'Brands',
        'modules.index' => 'Modules',
        'team.index' => 'Team',
        'warehouses.index' => 'Warehouses',
        'reports.index' => 'Reports',
        'analytics.index' => 'Analytics',
        'expenses.index' => 'Expenses',
        'purchases.index' => 'Purchases',
        'audit-logs.index' => 'Audit log',
        'settings.index' => 'Settings',
        'profile.edit' => 'Profile',
        'tokens.index' => 'API tokens',
        'tenants.index' => 'Your workspaces',
        'admin.dashboard' => 'Admin',
    ],

    // Paths are relative to the public disk root; absolute URLs are built from `url`.
    'default_image' => 'images/og/cultiv-default.jpg',

    'theme_color' => '#4f46e5',

    /*
    | Only these routes are allowed into a search index. Everything else is
    | noindex, nofollow.
    |
    | The default is deliberately a deny list by omission rather than a block list
    | of known-private pages: a page added later is unindexed until somebody
    | deliberately opts it in here. Forgetting to protect a new tenant-scoped page
    | is then impossible, whereas forgetting to block one would leak it.
    */
    'indexable_routes' => [
        'home',
    ],

    /*
    | Sitemap contents.
    |
    | The NAMES of the routes a crawler may index — never a hand-written list of URLs.
    | SitemapController re-checks every name against the live route table and drops
    | anything carrying auth / verified / tenant / platform-admin middleware, so
    | adding a private route here by mistake cannot leak it.
    |
    | Cultiv currently has exactly one public page. The rest of the 220 named routes
    | need a signed-in user, and usually a workspace on top. That is not a gap in this
    | file; it is what the application is.
    |
    | When a genuinely public page is added (a /pricing page, a public help article),
    | add its route NAME here and the sitemap picks it up on its own.
    */
    'sitemap' => [
        'routes' => [
            'home',
        ],
    ],

    /*
    | Set to '@handle' only once the account genuinely exists. A fabricated handle
    | makes Twitter attribute the card to whoever owns that name.
    */
    'twitter_handle' => null,

    'organization' => [
        'name' => 'Cultiv',
        'url' => 'https://cultiv.id',
        'logo' => 'images/logo.png',
        'description' => 'Cultiv adalah SaaS untuk mengelola operasional bisnis dalam satu workspace.',
    ],

    'software_application' => [
        'application_category' => 'BusinessApplication',
        'operating_system' => 'Web',
    ],
];
