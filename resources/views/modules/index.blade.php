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
                    $gateReason = $planGates[$module->key] ?? null;
                    $isBlockedByPlan = $gateReason !== null;
                    $isActive = ($install?->isActive() ?? false) && ! $isBlockedByPlan;
                    $isInstalled = $install?->isInstalled() ?? false;
                    $isPos = $module->key === 'pos';
                    $hasDetails = $isPos || $module->key === 'ai_agent';
                    $detailModal = $isPos ? 'pos-module-details' : 'ai-assistant-module-details';
                    $isPlatformMaintenance = $module->availability_status === \App\Models\Module::STATUS_MAINTENANCE;
                @endphp

                <div class="flex flex-col justify-between rounded-xl border border-gray-200 bg-white p-6 shadow-sm hover:shadow-md transition">
                    <div>
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
                                <x-nav-icon :name="$module->icon ?: 'cube'" class="h-6 w-6" />
                            </div>
                            <div>
                                @if ($isPlatformMaintenance)
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700">
                                        {{ __('Under maintenance') }}
                                    </span>
                                @elseif ($isActive)
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
                                    {{ __($module->name) }}
                                </a>
                                <span class="ml-1 text-xs font-normal text-gray-400">v{{ $module->version }}</span>
                            </h3>
                            <p class="mt-2 text-sm text-gray-600 line-clamp-3">
                                {{ $module->key === 'pos' ? __('Find products, scan barcodes, calculate sales totals, and record checkouts.') : __($module->description) }}
                            </p>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <span class="inline-flex items-center rounded bg-indigo-600 px-2 py-0.5 font-medium text-white">
                                {{ ! empty(config("modules.manifests.{$module->key}.plan_feature")) ? __('Included with an eligible plan') : $module->priceLabel() }}
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
                        @if ($isPlatformMaintenance)
                            <span class="inline-flex items-center rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">
                                {{ __('Installation and activation are temporarily unavailable.') }}
                            </span>
                        @elseif (! $canManage)
                            @if ($hasDetails)
                                <div class="grid grid-cols-[minmax(0,7fr)_minmax(0,3fr)] items-stretch gap-2">
                                    <span class="inline-flex items-center text-xs text-gray-400">{{ __('Admin access required.') }}</span>
                                    <button type="button"
                                            class="inline-flex min-w-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                            @click="$dispatch('open-modal', '{{ $detailModal }}')">
                                        {{ __('Module details') }}
                                    </button>
                                </div>
                            @else
                                <span class="text-xs text-gray-400">{{ __('Admin access required.') }}</span>
                            @endif
                        @elseif ($isBlockedByPlan)
                            @if ($hasDetails)
                                <div class="grid grid-cols-[minmax(0,7fr)_minmax(0,3fr)] gap-2">
                                    <a href="{{ route('billing.index') }}" class="inline-flex min-w-0 items-center justify-center rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">
                                        {{ __('Upgrade to unlock') }}
                                    </a>
                                    <button type="button"
                                            class="inline-flex min-w-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                            @click="$dispatch('open-modal', '{{ $detailModal }}')">
                                        {{ __('Module details') }}
                                    </button>
                                </div>
                            @else
                                <a href="{{ route('billing.index') }}" class="inline-flex w-full items-center justify-center rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">
                                    {{ __('Upgrade to unlock') }}
                                </a>
                            @endif
                        @elseif ($isActive)
                            @if ($hasDetails)
                                <div class="grid grid-cols-[minmax(0,7fr)_minmax(0,3fr)] gap-2">
                                    @if ($module->route && Route::has($module->route))
                                        <a href="{{ route($module->route) }}" class="inline-flex min-w-0 items-center justify-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                            {{ __('Open') }}
                                        </a>
                                    @endif
                                    <button type="button"
                                            class="inline-flex min-w-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                            @click="$dispatch('open-modal', '{{ $detailModal }}')">
                                        {{ __('Module details') }}
                                    </button>
                                </div>
                                @if (! $module->is_core)
                                    <form method="POST" action="{{ route('modules.deactivate', $module) }}" class="mt-2">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                            {{ __('Deactivate') }}
                                        </button>
                                    </form>
                                @endif
                            @else
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
                            @endif
                        @elseif ($isInstalled)
                            @if ($hasDetails)
                                <div class="grid grid-cols-[minmax(0,7fr)_minmax(0,3fr)] gap-2">
                                    <form method="POST" action="{{ route('modules.activate', $module) }}" class="min-w-0">
                                        @csrf
                                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                                            {{ __('Activate') }}
                                        </button>
                                    </form>
                                    <button type="button"
                                            class="inline-flex min-w-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                            @click="$dispatch('open-modal', '{{ $detailModal }}')">
                                        {{ __('Module details') }}
                                    </button>
                                </div>
                                @if (! $module->is_core)
                                    <form method="POST" action="{{ route('modules.uninstall', $module) }}" class="mt-2">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs text-gray-400 hover:text-red-600 px-2 py-2">
                                            {{ __('Uninstall') }}
                                        </button>
                                    </form>
                                @endif
                            @else
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
                            @endif
                        @else
                            @if ($hasDetails)
                                <div class="grid grid-cols-[minmax(0,7fr)_minmax(0,3fr)] gap-2">
                                    <form method="POST" action="{{ route('modules.install', $module) }}" class="min-w-0">
                                        @csrf
                                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                            {{ __('Install') }}
                                        </button>
                                    </form>
                                    <button type="button"
                                            class="inline-flex min-w-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                            @click="$dispatch('open-modal', '{{ $detailModal }}')">
                                        {{ __('Module details') }}
                                    </button>
                                </div>
                            @else
                                <form method="POST" action="{{ route('modules.install', $module) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                        {{ __('Install') }}
                                    </button>
                                </form>
                            @endif
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-full rounded-xl border border-dashed border-gray-300 bg-white p-12 text-center text-gray-500">
                    {{ __('No modules available yet.') }}
                </div>
            @endforelse
        </div>

        @if ($modules->contains(fn ($module) => $module->key === 'pos'))
        <x-modal name="pos-module-details" maxWidth="lg" focusable>
            <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-6 py-5">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('POS (Point of Sale)') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('Sales tools connected to your Cultiv One workspace.') }}</p>
                </div>
                <button
                    type="button"
                    class="rounded-md p-1 text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    aria-label="{{ __('Close') }}"
                    x-on:click="$dispatch('close-modal', 'pos-module-details')">
                    <span aria-hidden="true" class="text-xl leading-none">&times;</span>
                </button>
            </div>

            <div class="max-h-[70vh] space-y-6 overflow-y-auto px-5 pt-7 pb-6 sm:px-6 sm:pt-8 sm:pb-7">
                <section>
                    <h4 class="text-sm font-semibold text-gray-900">{{ __('What is POS?') }}</h4>
                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('POS (Point of Sale) helps you record and process sales transactions as they happen.') }}</p>
                </section>

                <section>
                    <h4 class="text-sm font-semibold text-gray-900">{{ __('How POS helps') }}</h4>
                    <ul class="mt-2 space-y-2 text-sm leading-6 text-gray-600">
                        <li>{{ __('Search products by name or SKU, or scan a barcode.') }}</li>
                        <li>{{ __('Add products to a cart and adjust item quantities.') }}</li>
                        <li>{{ __('Automatically calculate subtotals, discounts, tax, and totals.') }}</li>
                        <li>{{ __('Record the payment method, amount paid, and change.') }}</li>
                        <li>{{ __('Completed sales update stock for products with inventory tracking enabled.') }}</li>
                        <li>{{ __('Keep each transaction in your workspace sales history.') }}</li>
                    </ul>
                </section>

                <p class="text-sm leading-6 text-gray-600">{{ __('POS uses the products and inventory already in your workspace, and records completed checkouts as sales.') }}</p>
            </div>

            <div class="flex justify-end border-t border-gray-100 bg-gray-50 px-6 py-4">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'pos-module-details')">
                    {{ __('Close') }}
                </x-secondary-button>
            </div>
        </x-modal>
        @endif

        @if ($modules->contains(fn ($module) => $module->key === 'ai_agent'))
        <x-modal name="ai-assistant-module-details" maxWidth="lg" focusable>
            <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-6 py-5">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('AI Assistant Telegram') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('Manage your workspace through Telegram with help from AI.') }}</p>
                </div>
                <button
                    type="button"
                    class="rounded-md p-1 text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    aria-label="{{ __('Close') }}"
                    x-on:click="$dispatch('close-modal', 'ai-assistant-module-details')">
                    <span aria-hidden="true" class="text-xl leading-none">&times;</span>
                </button>
            </div>

            <div class="max-h-[70vh] space-y-6 overflow-y-auto px-5 pt-7 pb-6 sm:px-6 sm:pt-8 sm:pb-7">
                <section>
                    <h4 class="text-sm font-semibold text-gray-900">{{ __('What is AI Assistant Telegram?') }}</h4>
                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('AI Assistant Telegram lets you ask workspace business questions through a linked Telegram account.') }}</p>
                </section>

                <section>
                    <h4 class="text-sm font-semibold text-gray-900">{{ __('How AI Assistant Telegram helps') }}</h4>
                    <ul class="mt-2 space-y-2 text-sm leading-6 text-gray-600">
                        <li>{{ __('Search workspace products by name, SKU, or barcode.') }}</li>
                        <li>{{ __('Check current stock and review recent sales summaries.') }}</li>
                        <li>{{ __('Search workspace customers when your account has permission.') }}</li>
                        <li>{{ __('Prepare a sale for your review and explicit confirmation before it is recorded.') }}</li>
                    </ul>
                </section>

                <p class="text-sm leading-6 text-gray-600">{{ __('AI Assistant Telegram uses your workspace data and existing permissions. Link your Telegram account from the module after installation.') }}</p>
            </div>

            <div class="flex justify-end border-t border-gray-100 bg-gray-50 px-6 py-4">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'ai-assistant-module-details')">
                    {{ __('Close') }}
                </x-secondary-button>
            </div>
        </x-modal>
        @endif
    </div>
</x-app-layout>
