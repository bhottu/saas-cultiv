<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('modules.index') }}" class="text-gray-400 hover:text-gray-600">&larr; {{ __('Back to Modules') }}</a>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ $module->name }}</h2>
            </div>
            <span class="text-xs text-gray-400">v{{ $module->version }}</span>
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-6 py-6 sm:px-6 lg:px-8">
        @if (session('status'))
            <div class="rounded-lg p-4 {{ (session('status.type') ?? 'success') === 'error' ? 'bg-red-50 text-red-800 border border-red-200' : 'bg-emerald-50 text-emerald-800 border border-emerald-200' }}">
                <span class="text-sm font-medium">{{ session('status.message') }}</span>
            </div>
        @endif

        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex items-start gap-4">
                <div class="flex h-14 w-14 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                    <x-nav-icon :name="$module->icon ?: 'cube'" class="h-7 w-7" />
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-bold text-gray-900">{{ $module->name }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ $module->description }}</p>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        <span class="rounded bg-indigo-600 px-2 py-0.5 font-medium text-white">
                            {{ ! empty(config("modules.manifests.{$module->key}.plan_feature")) ? __('Included with an eligible plan') : $module->priceLabel() }}
                        </span>
                        @if ($module->min_plan)
                            <span class="rounded bg-indigo-50 px-2 py-0.5 font-medium text-indigo-700">{{ __('Requires :plan', ['plan' => ucfirst($module->min_plan)]) }}</span>
                        @endif
                        @if ($module->is_core)
                            <span class="rounded bg-purple-50 px-2 py-0.5 font-medium text-purple-700">{{ __('Core') }}</span>
                        @endif
                    </div>
                </div>
            </div>

            @if ($planGate)
                <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    <strong>{{ __('Plan Upgrade Required:') }}</strong> {{ $planGate }}
                    <a href="{{ route('billing.index') }}" class="font-semibold underline ml-1">{{ __('View Plans') }}</a>
                </div>
            @endif

            <div class="mt-6 border-t border-gray-100 pt-6">
                @if (! $canManage)
                    <p class="text-sm text-gray-500">{{ __('Only workspace admins can manage modules.') }}</p>
                @elseif ($planGate)
                    <a href="{{ route('billing.index') }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        {{ __('Upgrade Plan') }}
                    </a>
                @elseif ($install?->isActive())
                    <div class="flex items-center gap-3">
                        @if ($module->route && Route::has($module->route))
                            <a href="{{ route($module->route) }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                {{ __('Open Module') }}
                            </a>
                        @endif
                        @if (! $module->is_core)
                            <form method="POST" action="{{ route('modules.deactivate', $module) }}">
                                @csrf
                                <button type="submit" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                    {{ __('Deactivate') }}
                                </button>
                            </form>
                        @endif
                    </div>
                @elseif ($install?->isInstalled())
                    <div class="flex items-center gap-3">
                        <form method="POST" action="{{ route('modules.activate', $module) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                                {{ __('Activate for Workspace') }}
                            </button>
                        </form>
                        @if (! $module->is_core)
                            <form method="POST" action="{{ route('modules.uninstall', $module) }}" onsubmit="return confirm('Uninstall module?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-sm text-gray-500 hover:text-red-600">
                                    {{ __('Uninstall') }}
                                </button>
                            </form>
                        @endif
                    </div>
                @else
                    <form method="POST" action="{{ route('modules.install', $module) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            {{ __('Install Module') }}
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
