<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Modules') }}</h2>
                <p class="text-sm text-gray-500">{{ __('Extend your workspace with features and integrations.') }}</p>
            </div>
            @if ($tenant)
                <span class="inline-flex items-center rounded-md bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">
                    {{ __('Workspace: :name', ['name' => $tenant->name]) }}
                </span>
            @endif
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 py-6 sm:px-6 lg:px-8">
        @if (session('status'))
            <div class="rounded-lg p-4 {{ (session('status.type') ?? 'success') === 'error' ? 'bg-red-50 text-red-800 border border-red-200' : 'bg-emerald-50 text-emerald-800 border border-emerald-200' }}">
                <span class="text-sm font-medium">{{ session('status.message') }}</span>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            @forelse ($modules as $module)
                @php
                    $install = $installs->get($module->key);
                    $isActive = $install?->isActive() ?? false;
                    $isInstalled = $install?->isInstalled() ?? false;
                    $gateReason = $planGates[$module->key] ?? null;
                    $isBlockedByPlan = $gateReason !== null;
                @endphp

                <div class="flex flex-col justify-between rounded-xl border border-gray-200 bg-white p-6 shadow-sm hover:shadow-md transition">
                    <div>
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
                                <x-nav-icon :name="$module->icon ?: 'cube'" class="h-6 w-6" />
                            </div>
                            <div>
                                @if ($isActive)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">
                                        {{ __('Active') }}
                                    </span>
                                @elseif ($isInstalled)
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700">
                                        {{ $install->statusLabel() }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">
                                        {{ __('Available') }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div class="mt-4">
                            <h3 class="text-base font-semibold text-gray-900">
                                <a href="{{ route('modules.show', $module) }}" class="hover:text-indigo-600">
                                    {{ $module->name }}
                                </a>
                                <span class="ml-1 text-xs font-normal text-gray-400">v{{ $module->version }}</span>
                            </h3>
                            <p class="mt-2 text-sm text-gray-600 line-clamp-3">{{ $module->description }}</p>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <span class="inline-flex items-center rounded bg-indigo-600 px-2 py-0.5 font-medium text-white">
                                {{ $module->priceLabel() }}
                            </span>
                            @if ($module->min_plan)
                                <span class="inline-flex items-center rounded bg-indigo-50 px-2 py-0.5 font-medium text-indigo-700">
                                    {{ __('Requires :plan', ['plan' => ucfirst($module->min_plan)]) }}
                                </span>
                            @endif
                        </div>

                        @if ($isBlockedByPlan)
                            <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-2.5 text-xs text-amber-800">
                                {{ $gateReason }}
                                <a href="{{ route('billing.index') }}" class="font-semibold underline ml-1">{{ __('Upgrade') }}</a>
                            </div>
                        @endif
                    </div>

                    <div class="mt-6 border-t border-gray-100 pt-4">
                        @if (! $canManage)
                            <span class="text-xs text-gray-400">{{ __('Admin access required.') }}</span>
                        @elseif ($isBlockedByPlan)
                            <a href="{{ route('billing.index') }}" class="inline-flex w-full items-center justify-center rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">
                                {{ __('Upgrade to unlock') }}
                            </a>
                        @elseif ($isActive)
                            <div class="flex items-center gap-2">
                                @if ($module->route && Route::has($module->route))
                                    <a href="{{ route($module->route) }}" class="inline-flex flex-1 items-center justify-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                        {{ __('Open') }}
                                    </a>
                                @endif
                                @if (! $module->is_core)
                                    <form method="POST" action="{{ route('modules.deactivate', $module) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                            {{ __('Deactivate') }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @elseif ($isInstalled)
                            <div class="flex items-center gap-2">
                                <form method="POST" action="{{ route('modules.activate', $module) }}" class="flex-1">
                                    @csrf
                                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                                        {{ __('Activate') }}
                                    </button>
                                </form>
                                @if (! $module->is_core)
                                    <form method="POST" action="{{ route('modules.uninstall', $module) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs text-gray-400 hover:text-red-600 px-2 py-2">
                                            {{ __('Uninstall') }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @else
                            <form method="POST" action="{{ route('modules.install', $module) }}">
                                @csrf
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                    {{ __('Install') }}
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-full rounded-xl border border-dashed border-gray-300 bg-white p-12 text-center text-gray-500">
                    {{ __('No modules available yet.') }}
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
