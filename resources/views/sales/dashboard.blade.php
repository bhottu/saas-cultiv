<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ __('Sales dashboard') }}</h2>
                <p class="truncate text-sm text-gray-500">{{ __('Today, this month and the products that sell best.') }}</p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('sales.index') }}" class="text-sm text-gray-600 underline">{{ __('All sales') }}</a>
                <a href="{{ route('sales.create') }}"
                   class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    {{ __('New sale') }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        @include('sales.partials.dashboard-summary', ['dashboardData' => $dashboardData])
    </div>
</x-app-layout>
