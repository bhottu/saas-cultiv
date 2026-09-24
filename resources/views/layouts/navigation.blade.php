@php
    // Single source of truth for the shared shell navigation.
    //
    // * Every href is a named route — no hard-coded URLs.
    // * Items are gated by the EXISTING authorization stack (TenantContext + the
    //   business permission registry) — no second RBAC is introduced. Hiding an item
    //   is a UI concern only: the controllers keep enforcing authorization server-side.
    // * Modules that have routes but no view yet (sales, purchases, customers,
    //   suppliers, stock, ...) are deliberately NOT linked, so no menu entry ever
    //   leads to a missing view.
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

    $workspaceItems = [];

    if ($inWorkspace) {
        $workspaceItems[] = [
            'label' => __('Files'),
            'icon' => 'folder',
            'href' => route('files.index'),
            'active' => request()->routeIs('files.*'),
        ];
        $workspaceItems[] = [
            'label' => __('Team'),
            'icon' => 'users',
            'href' => route('team.index'),
            'active' => request()->routeIs('team.*'),
        ];
        $workspaceItems[] = [
            'label' => __('Billing'),
            'icon' => 'credit-card',
            'href' => route('billing.index'),
            'active' => request()->routeIs('billing.*'),
        ];
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
        [
            'label' => __('API tokens'),
            'icon' => 'key',
            'href' => route('tokens.index'),
            'active' => request()->routeIs('tokens.*'),
        ],
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

    $navGroups = array_values(array_filter([
        ['label' => __('Overview'), 'items' => $overview],
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
    <div class="flex h-16 shrink-0 items-center gap-2 border-b border-gray-200 px-5">
        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-2">
            <x-application-logo class="h-8 w-8 shrink-0 fill-current text-indigo-600" />
            <span class="truncate text-sm font-semibold text-gray-900">{{ config('app.name', 'Laravel') }}</span>
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
        <div class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-gray-200 px-4">
            <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-2" @click="sidebarOpen = false">
                <x-application-logo class="h-8 w-8 shrink-0 fill-current text-indigo-600" />
                <span class="truncate text-sm font-semibold text-gray-900">{{ config('app.name', 'Laravel') }}</span>
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