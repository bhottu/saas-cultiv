@php
    // Header data for the shared shell. One tenant lookup + one notification lookup per
    // page render; both are scoped to the authenticated user (never global SaaS data).
    $ctx = app('tenant.context');
    $shellUser = Auth::user();
    $workspace = $ctx->tenant();
    $role = $ctx->role();

    $shellTenants = $shellUser ? $shellUser->tenants()->orderBy('tenants.name')->get() : collect();
    $shellNotifications = $shellUser ? $shellUser->notifications()->latest()->take(5)->get() : collect();
    $shellUnread = $shellUser ? $shellUser->unreadNotifications()->count() : 0;
    $shellInitials = $shellUser
        ? collect(explode(' ', trim($shellUser->name)))->filter()->take(2)
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('')
        : '';
@endphp

<header class="sticky top-0 z-30 border-b border-gray-200 bg-white">
    <div class="flex min-h-[3.75rem] flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2 sm:px-6 lg:px-8">
        {{-- Mobile: reveal the sidebar drawer --}}
        <button type="button" @click="sidebarOpen = true"
                class="-ml-1 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 lg:hidden"
                aria-label="{{ __('Open navigation') }}">
            <x-nav-icon name="menu" class="h-6 w-6" />
        </button>

        {{-- Page heading + page-level actions, supplied by each page through the "header" slot --}}
        <div class="order-last w-full min-w-0 sm:order-none sm:w-auto sm:flex-1">
            @isset($header)
                {{ $header }}
            @else
                <h1 class="text-lg font-semibold text-gray-900">{{ config('app.name', 'Laravel') }}</h1>
            @endisset
        </div>

        <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
            {{-- Workspace switcher (tenant context already validated by the tenant middleware) --}}
            @if ($workspace)
                <x-dropdown align="right" width="w-64">
                    <x-slot name="trigger">
                        <button type="button"
                                class="flex max-w-[11rem] items-center gap-2 rounded-lg px-2 py-1.5 text-start hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                            <x-nav-icon name="office" class="hidden h-5 w-5 text-gray-400 sm:block" />
                            <span class="min-w-0">
                                <span class="block truncate text-xs text-gray-400">{{ __('Workspace') }}</span>
                                <span class="block truncate text-sm font-medium text-gray-800">{{ $workspace->name }}</span>
                            </span>
                            <x-nav-icon name="chevron-down" class="h-4 w-4 text-gray-400" />
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <div class="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-gray-400">
                            {{ __('Switch workspace') }}
                        </div>

                        @foreach ($shellTenants as $shellTenant)
                            <form method="POST" action="{{ route('tenants.switch', $shellTenant) }}">
                                @csrf
                                <button type="submit"
                                        class="flex w-full items-center justify-between gap-2 px-4 py-2 text-start text-sm {{ $shellTenant->id === $workspace->id ? 'font-semibold text-indigo-700' : 'text-gray-700 hover:bg-gray-100' }}">
                                    <span class="truncate">{{ $shellTenant->name }}</span>
                                    @if ($shellTenant->id === $workspace->id)
                                        <span class="text-xs font-medium text-indigo-500">{{ __('current') }}</span>
                                    @endif
                                </button>
                            </form>
                        @endforeach

                        <div class="mt-1 border-t border-gray-100 pt-1">
                            <x-dropdown-link :href="route('tenants.index')">
                                {{ __('Manage workspaces') }}
                            </x-dropdown-link>
                        </div>
                    </x-slot>
                </x-dropdown>
            @else
                <a href="{{ route('tenants.index') }}"
                   class="rounded-lg px-3 py-2 text-sm font-medium text-indigo-600 hover:bg-indigo-50">
                    {{ __('Select workspace') }}
                </a>
            @endif

            @auth
                {{-- Notifications (same source as the dashboard panel) --}}
                <x-dropdown align="right" width="w-80">
                    <x-slot name="trigger">
                        <button type="button"
                                class="relative inline-flex h-10 w-10 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                                aria-label="{{ __('Notifications') }}">
                            <x-nav-icon name="bell" class="h-6 w-6" />
                            @if ($shellUnread > 0)
                                <span class="absolute right-1 top-1 inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-indigo-600 px-1 text-xs font-semibold text-white">
                                    {{ $shellUnread > 9 ? '9+' : $shellUnread }}
                                </span>
                            @endif
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <div class="flex items-center justify-between px-4 py-2 text-xs font-semibold uppercase tracking-wider text-gray-400">
                            <span>{{ __('Notifications') }}</span>
                            @if ($shellUnread > 0)
                                <span class="text-indigo-600">{{ $shellUnread }} {{ __('new') }}</span>
                            @endif
                        </div>

                        @forelse ($shellNotifications as $notification)
                            <div class="border-t border-gray-100 px-4 py-2 text-sm text-gray-700">
                                <div class="flex items-start gap-2">
                                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full {{ $notification->read_at ? 'bg-transparent' : 'bg-indigo-500' }}"></span>
                                    <span class="min-w-0">
                                        <span class="block">{{ $notification->data['message'] ?? __('Notification') }}</span>
                                        <span class="block text-xs text-gray-400">{{ $notification->created_at->diffForHumans() }}</span>
                                    </span>
                                </div>
                            </div>
                        @empty
                            <div class="border-t border-gray-100 px-4 py-3 text-sm text-gray-500">
                                {{ __('Nothing new.') }}
                            </div>
                        @endforelse
                    </x-slot>
                </x-dropdown>

                {{-- User menu: keeps the existing Profile / Log Out entries and adds the
                     workspace-adjacent pages that were previously unreachable from the UI. --}}
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button type="button"
                                class="flex items-center gap-2 rounded-lg px-1.5 py-1.5 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-semibold text-white">
                                {{ $shellInitials }}
                            </span>
                            <span class="hidden text-sm font-medium text-gray-700 sm:block">{{ $shellUser->name }}</span>
                            <x-nav-icon name="chevron-down" class="hidden h-4 w-4 text-gray-400 sm:block" />
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <div class="px-4 py-2">
                            <div class="truncate text-sm font-medium text-gray-800">{{ $shellUser->name }}</div>
                            <div class="truncate text-xs text-gray-500">{{ $shellUser->email }}</div>
                            @if ($role)
                                <div class="mt-1 text-xs text-gray-400">{{ __('Role') }}: {{ $role }}</div>
                            @endif
                        </div>

                        <div class="border-t border-gray-100 pt-1">
                            <x-dropdown-link :href="route('profile.edit')">
                                {{ __('Profile') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('tokens.index')">
                                {{ __('API tokens') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('tenants.index')">
                                {{ __('Workspaces') }}
                            </x-dropdown-link>
                        </div>

                        <div class="border-t border-gray-100 pt-1">
                            <!-- Authentication -->
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf

                                <x-dropdown-link :href="route('logout')"
                                        onclick="event.preventDefault();
                                                    this.closest('form').submit();">
                                    {{ __('Log Out') }}
                                </x-dropdown-link>
                            </form>
                        </div>
                    </x-slot>
                </x-dropdown>
            @endauth
        </div>
    </div>
</header>