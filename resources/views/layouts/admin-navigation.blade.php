{{--
    Platform Admin sidebar. It reuses the shared shell (x-app-layout) and the same
    components as the tenant app — only the menu groups differ. The group is only
    rendered for platform admins; the server-side `platform.admin` middleware remains
    the real gate, this is UX only.
--}}
@php
    $adminNav = [
        ['label' => __('Dashboard'), 'icon' => 'home', 'href' => route('admin.dashboard'), 'active' => request()->routeIs('admin.dashboard')],
        ['label' => __('Users'), 'icon' => 'users', 'href' => route('admin.users.index'), 'active' => request()->routeIs('admin.users.*')],
        ['label' => __('Workspaces'), 'icon' => 'office', 'href' => route('admin.workspaces.index'), 'active' => request()->routeIs('admin.workspaces.*')],
        ['label' => __('Products'), 'icon' => 'cube', 'href' => route('admin.products.index'), 'active' => request()->routeIs('admin.products.*')],
        ['label' => __('Customers'), 'icon' => 'user-circle', 'href' => route('admin.customers.index'), 'active' => request()->routeIs('admin.customers.*')],
        ['label' => __('Sales'), 'icon' => 'shopping-cart', 'href' => route('admin.sales.index'), 'active' => request()->routeIs('admin.sales.*')],
        ['label' => __('Purchases'), 'icon' => 'arrow-path', 'href' => route('admin.purchases.index'), 'active' => request()->routeIs('admin.purchases.*')],
        ['label' => __('Inventory'), 'icon' => 'folder', 'href' => route('admin.inventory.index'), 'active' => request()->routeIs('admin.inventory.*')],
        ['label' => __('Subscriptions'), 'icon' => 'chart-bar', 'href' => route('admin.subscriptions.index'), 'active' => request()->routeIs('admin.subscriptions.*')],
        ['label' => __('Payments'), 'icon' => 'credit-card', 'href' => route('admin.payments.index'), 'active' => request()->routeIs('admin.payments.*')],
        ['label' => __('Payment settings'), 'icon' => 'cog', 'href' => route('admin.billing.edit'), 'active' => request()->routeIs('admin.billing.*')],
        ['label' => __('Plans'), 'icon' => 'tag', 'href' => route('admin.plans.index'), 'active' => request()->routeIs('admin.plans.*')],
        ['label' => __('Module management'), 'icon' => 'cube', 'href' => route('admin.modules.index'), 'active' => request()->routeIs('admin.modules.*')],
        ['label' => __('API Access'), 'icon' => 'key', 'href' => route('admin.api.index'), 'active' => request()->routeIs('admin.api.*')],
        ['label' => __('AI Assistant'), 'icon' => 'sparkles', 'href' => route('admin.ai.edit'), 'active' => request()->routeIs('admin.ai.*')],
        ['label' => __('SEO'), 'icon' => 'globe', 'href' => route('admin.seo.edit'), 'active' => request()->routeIs('admin.seo.*')],
        ['label' => __('Audit Logs'), 'icon' => 'shield-check', 'href' => route('admin.audit-logs.index'), 'active' => request()->routeIs('admin.audit-logs.*')],
    ];
@endphp

<nav aria-label="{{ __('Platform admin') }}" tabindex="0" class="h-[calc(100dvh-5.5rem)] overflow-y-scroll overflow-x-hidden overscroll-y-contain rounded-lg bg-white p-2 shadow">
    <ul class="space-y-0.5">
        @foreach ($adminNav as $item)
            <li>
                <a href="{{ $item['href'] }}"
                   @if ($item['active']) aria-current="page" @endif
                   class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium
                          {{ $item['active'] ? 'bg-indigo-50 text-indigo-700' : 'text-gray-700 hover:bg-gray-50' }}">
                    <x-nav-icon :name="$item['icon']" class="h-4 w-4 shrink-0" />
                    <span>{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
