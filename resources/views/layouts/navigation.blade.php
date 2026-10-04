@php
    use Illuminate\Support\Facades\Route;

    // Single source of truth for the shared shell navigation.
    //
    // * Every href is a named route — no hard-coded URLs.
    // * Items are gated by the EXISTING authorization stack (TenantContext + the
    //   business permission registry) — no second RBAC is introduced. Hiding an item
    //   is a UI concern only: the controllers keep enforcing authorization server-side.
    // * Every entry below points at a page whose view ships in this build, so a menu item
    //   can never lead to a missing view. Purchasing, suppliers, expenses and business
    //   invoices were linked here once their views landed — not before, and not after.
    $ctx = app('tenant.context');
    $business = app(\App\Services\BusinessAuthorization::class);
    $shellUser = Auth::user();
    $workspace = $ctx->tenant();
    $role = $ctx->role();
    $inWorkspace = $workspace !== null;

    $overview = [
        [
            'label' => __('Dashboard'),
            'icon' => 'home',
            'href' => route('dashboard'),
            'active' => request()->routeIs('dashboard'),
        ],
    ];

    $inventory = [];

    if ($business->can('products.view')) {
        $inventory[] = [
            'label' => __('Products'),
            'icon' => 'cube',
            'href' => route('products.index'),
            'active' => request()->routeIs('products.*'),
        ];
    }

    if ($business->can('inventory.view')) {
        $inventory[] = [
            'label' => __('Stock'),
            'icon' => 'folder',
            'href' => route('stock.index'),
            'active' => request()->routeIs('stock.*'),
        ];
    }

    if ($business->can('warehouses.view')) {
        $inventory[] = [
            'label' => __('Warehouses'),
            'icon' => 'warehouse',
            'href' => route('warehouses.index'),
            'active' => request()->routeIs('warehouses.*'),
        ];
    }

    if ($business->can('categories.view')) {
        $inventory[] = [
            'label' => __('Categories'),
            'icon' => 'tag',
            'href' => route('categories.index'),
            'active' => request()->routeIs('categories.*'),
        ];
    }

    if ($business->can('brands.view')) {
        $inventory[] = [
            'label' => __('Brands'),
            'icon' => 'bookmark',
            'href' => route('brands.index'),
            'active' => request()->routeIs('brands.*'),
        ];
    }

    // Sales: orders, customers, returns and reports. The detailed sales dashboard stays
    // available at its existing URL but is intentionally not a second sidebar dashboard.
    // The report is financial, so it follows the existing reports permission.
    $sales = [];

    if ($business->can('sales.view')) {
        $sales[] = [
            'label' => __('Orders'),
            'icon' => 'shopping-cart',
            'href' => route('sales.index'),
            'active' => request()->routeIs('sales.index', 'sales.create', 'sales.show', 'sales.print', 'sales.dashboard'),
        ];
    }

    if ($business->can('customers.view')) {
        $sales[] = [
            'label' => __('Customers'),
            'icon' => 'users',
            'href' => route('customers.index'),
            'active' => request()->routeIs('customers.*'),
        ];
    }

    if ($business->can('sales.view')) {
        $sales[] = [
            'label' => __('Returns'),
            'icon' => 'arrow-path',
            'href' => route('sales.returns'),
            'active' => request()->routeIs('sales.returns', 'sales.return'),
        ];
    }

    if ($business->can('reports.view')) {
        $sales[] = [
            'label' => __('Reports'),
            'icon' => 'chart-bar',
            'href' => route('sales.report'),
            'active' => request()->routeIs('sales.report'),
        ];
    }

    // Business invoices — the receivable/payable documents the sale and purchase flows
    // generate. They live behind the same sales.view permission the invoice controller
    // itself checks, so the menu mirrors the server-side gate exactly. The page filters
    // by `?type=purchase` for supplier bills, which is why purchasing links across to it.
    if ($business->can('sales.view')) {
        $sales[] = [
            'label' => __('Invoices'),
            'icon' => 'document-report',
            'href' => route('business-invoices.index'),
            'active' => request()->routeIs('business-invoices.*'),
        ];
    }

    // Purchasing: what the business buys, from whom, and what it costs to operate.
    // All three have shipped controllers, views and permissions for a long time but had
    // no menu entry at all, so they were reachable only by typing the URL. Grouped as the
    // "spend" side of the business, mirroring the sales group above.
    $purchasing = [];

    if ($business->can('purchases.view')) {
        $purchasing[] = [
            'label' => __('Purchase Orders'),
            'icon' => 'download',
            'href' => route('purchases.index'),
            'active' => request()->routeIs('purchases.*'),
        ];
    }

    if ($business->can('suppliers.view')) {
        $purchasing[] = [
            'label' => __('Suppliers'),
            'icon' => 'office',
            'href' => route('suppliers.index'),
            'active' => request()->routeIs('suppliers.*'),
        ];
    }

    if ($business->can('expenses.view')) {
        $purchasing[] = [
            'label' => __('Expenses'),
            'icon' => 'credit-card',
            'href' => route('expenses.index'),
            'active' => request()->routeIs('expenses.*'),
        ];
    }

    // Its own entry rather than a sub-link of Expenses: it is a real tenant-scoped
    // resource, exactly like Categories and Brands, and the expense form needs a
    // reachable path to it. Gated on expenses.view, the same verb its controller checks.
    if ($business->can('expenses.view')) {
        $purchasing[] = [
            'label' => __('Expense Categories'),
            'icon' => 'tag',
            'href' => route('expense-categories.index'),
            'active' => request()->routeIs('expense-categories.*'),
        ];
    }

    // Advanced reporting + analytics are plan entitlements (Pro/Business). The menu
    // entries ALWAYS stay visible (subject to the existing RBAC permission) — a Free
    // user should be able to see what higher plans add — and carry a lock marker
    // when the workspace lacks the entitlement. The marker is purely a visual
    // indication: the routes stay gated server-side by the plan.feature middleware,
    // which answers with the existing upgrade prompt, never with data.
    $usage = app(\App\Services\UsageService::class);
    $workspace = app('tenant.context')->tenant();

    // The ONE place an entitlement becomes a navigation lock, so this file can never
    // grow a second "premium features" list that drifts from the backend's.
    // Wording note: the hint deliberately does not use the bare word "Available" —
    // the module centre reserves that exact word for a module STATE ("Available"),
    // and a generic nav tooltip echoing it would blur the two meanings.
    $navLock = function (string $feature) use ($usage, $workspace): array {
        if ($workspace !== null && $usage->allows($workspace, $feature)) {
            return ['locked' => false];
        }

        return [
            'locked' => true,
            'lock-title' => __('Included in :plans plans.', ['plans' => $usage->featurePlans($feature)])
                . ' ' . __('Upgrade to unlock it.'),
        ];
    };

    $advanced = [];

    if ($business->can('reports.view')) {
        $advanced[] = [
            'label' => __('Advanced Reports'),
            'icon' => 'document-report',
            'href' => route('reports.index'),
            'active' => request()->routeIs('reports.*'),
        ] + $navLock('advanced_reports');
    }

    if ($business->can('reports.view')) {
        $advanced[] = [
            'label' => __('Analytics'),
            'icon' => 'chart-pie',
            'href' => route('analytics.index'),
            'active' => request()->routeIs('analytics.*'),
        ] + $navLock('advanced_analytics');
    }

    $workspaceItems = [];

    if ($inWorkspace) {
        // Files. Guarded on the route actually being registered rather than deleted
        // outright: the feature is switched off in config/saas.php, so this keeps the
        // menu honest in both states and a dead link can never appear while the routes
        // are unregistered (route() would throw on a missing name).
        if (Route::has('files.index')) {
            $workspaceItems[] = [
                'label' => __('Files'),
                'icon' => 'folder',
                'href' => route('files.index'),
                'active' => request()->routeIs('files.*'),
            ];
        }
        $workspaceItems[] = [
            'label' => __('Team'),
            'icon' => 'users',
            'href' => route('team.index'),
            'active' => request()->routeIs('team.*'),
        ];
        $workspaceItems[] = [
            'label' => __('Subscription'),
            'icon' => 'credit-card',
            'href' => route('billing.index'),
            'active' => request()->routeIs('billing.*'),
        ];

        if ($business->can('modules.view')) {
            $workspaceItems[] = [
                'label' => __('Modules'),
                'icon' => 'cube',
                'href' => route('modules.index'),
                'active' => request()->routeIs('modules.*'),
            ];
        }

        // Audit trail is a governance surface: the entry mirrors exactly what the
        // controller checks (the audit.view action mapped to the manage_settings
        // registry verb), so the menu never advertises a page the user can never open.
        // Without the plan entitlement the entry stays visible, lock-marked.
        if ($business->can('audit.view')) {
            $workspaceItems[] = [
                'label' => __('Audit Log'),
                'icon' => 'shield-check',
                'href' => route('audit-logs.index'),
                'active' => request()->routeIs('audit-logs.*'),
            ] + $navLock('audit_log');
        }
    }

    $workspaceItems[] = [
        'label' => __('Workspaces'),
        'icon' => 'office',
        'href' => route('tenants.index'),
        'active' => request()->routeIs('tenants.*'),
    ];

    $account = [
        [
            'label' => __('Profile'),
            'icon' => 'user-circle',
            'href' => route('profile.edit'),
            'active' => request()->routeIs('profile.*'),
        ],
        // Preferences hub. Profile above stays personal facts (name, email, password);
        // everything configurable — language, workspace branding — lives here. Kept in
        // the same group so it is one click from the account menu, and registered
        // outside the `tenant` group so it also works with no workspace selected.
        [
            'label' => __('Settings'),
            'icon' => 'cog',
            'href' => route('settings.index'),
            'active' => request()->routeIs('settings.*'),
        ],
        [
            'label' => __('API Tokens'),
            'icon' => 'key',
            'href' => route('tokens.index'),
            'active' => request()->routeIs('tokens.*'),
        ] + $navLock('api_access'),
    ];

    $platform = [];

    if ($shellUser?->is_platform_admin) {
        $platform[] = [
            'label' => __('Platform admin'),
            'icon' => 'shield-check',
            'href' => route('admin.dashboard'),
            'active' => request()->routeIs('admin.*'),
        ];
    }

    $moduleManager = app(\App\Services\ModuleManager::class);
    $moduleItems = $moduleManager->sidebarItems($workspace);
    foreach ($moduleItems as $mItem) {
        $targetGroup = strtolower($mItem['group'] ?? 'sales');
        if ($targetGroup === 'sales') {
            $sales[] = $mItem;
        } elseif ($targetGroup === 'purchasing') {
            // Reachable now that the group exists; a module may declare
            // 'sidebar_group' => 'Purchasing' and land in the right place.
            $purchasing[] = $mItem;
        } elseif ($targetGroup === 'inventory') {
            $inventory[] = $mItem;
        } else {
            $workspaceItems[] = $mItem;
        }
    }

    $navGroups = array_values(array_filter([
        ['label' => __('Overview'), 'items' => $overview],
        ['label' => __('Sales'), 'items' => $sales],
        ['label' => __('Purchasing'), 'items' => $purchasing],
        ['label' => __('Insights'), 'items' => $advanced],
        ['label' => __('Inventory'), 'items' => $inventory],
        ['label' => __('Workspace'), 'items' => $workspaceItems],
        ['label' => __('Account'), 'items' => $account],
        ['label' => __('Platform'), 'items' => $platform],
    ], fn ($group) => ! empty($group['items'])));
@endphp

{{-- Desktop sidebar --}}
<aside id="sidebar"
       class="hidden border-r border-gray-200 bg-white lg:fixed lg:inset-y-0 lg:left-0 lg:z-40 lg:flex lg:w-72 lg:flex-col">
    {{-- Brand --}}
    <div class="min-h-20 shrink-0 border-b border-gray-200 px-5 py-2.5">
        <a href="{{ route('dashboard') }}" class="block min-w-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
            <x-brand-lockup />
        </a>
    </div>

    {{-- Menu --}}
    <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="{{ __('Main navigation') }}">
        <x-sidebar-nav :groups="$navGroups" />
    </nav>

    {{-- Active workspace context --}}
    <div class="shrink-0 border-t border-gray-200 px-5 py-4">
        @if ($workspace)
            <div class="text-xs uppercase tracking-wider text-gray-400">{{ __('Workspace') }}</div>
            <div class="truncate text-sm font-medium text-gray-800">{{ $workspace->name }}</div>
            @if ($role)
                <span class="mt-1 inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                    {{ $role }}
                </span>
            @endif
        @else
            <a href="{{ route('tenants.index') }}" class="text-sm font-medium text-indigo-600 hover:underline">
                {{ __('Select a workspace') }}
            </a>
        @endif
    </div>
</aside>

{{-- Mobile drawer (same navigation, off-canvas) --}}
<div x-show="sidebarOpen"
     x-transition.opacity
     class="lg:hidden"
     style="display: none;"
     @keydown.escape.window="sidebarOpen = false">
    <div class="fixed inset-0 z-40 bg-gray-900/50" aria-hidden="true" @click="sidebarOpen = false"></div>

    <div id="mobile-sidebar"
         role="dialog"
         aria-modal="true"
         aria-label="{{ __('Navigation') }}"
         class="fixed inset-y-0 left-0 z-50 flex w-72 max-w-[85vw] flex-col bg-white shadow-xl">
        <div class="flex min-h-20 shrink-0 items-center justify-between gap-2 border-b border-gray-200 px-4 py-2">
            <a href="{{ route('dashboard') }}" class="block min-w-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" @click="sidebarOpen = false">
                <x-brand-lockup />
            </a>

            <button type="button" @click="sidebarOpen = false"
                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                    aria-label="{{ __('Close navigation') }}">
                <x-nav-icon name="close" class="h-5 w-5" />
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="{{ __('Main navigation') }}" @click="sidebarOpen = false">
            <x-sidebar-nav :groups="$navGroups" />
        </nav>

        <div class="shrink-0 border-t border-gray-200 px-5 py-4">
            @auth
                <div class="truncate text-sm font-medium text-gray-800">{{ Auth::user()->name }}</div>
                <div class="truncate text-xs text-gray-500">{{ Auth::user()->email }}</div>

                <form method="POST" action="{{ route('logout') }}" class="mt-3">
                    @csrf

                    <x-dropdown-link :href="route('logout')"
                            onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-dropdown-link>
                </form>
            @endauth
        </div>
    </div>
</div>