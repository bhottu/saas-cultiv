<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ __('Customer Report') }}</h2>
                <p class="truncate text-sm text-gray-500">{{ $period->label() }}</p>
            </div>
            <a href="{{ route('reports.index') }}" class="text-sm text-gray-600 underline">{{ __('All reports') }}</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        <x-report-filters :filters="$filters" :reset-url="route('reports.customers')" />

        <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
            <x-kpi-card :label="__('Buying customers')" :value="$summary['buying_customers']" />
            <x-kpi-card :label="__('Repeat customers')" :value="$summary['repeat_customers']" />
            <x-kpi-card :label="__('New customers')" :value="$summary['new_customers']" />
            <x-kpi-card :label="__('Repeat rate')" :value="$summary['repeat_rate'].'%'" />
        </div>

        <x-kpi-card :label="__('Average spend per order')"
                    :value="\App\Services\Money::format($summary['avg_spend_per_order'])" />

        @include('reports.partials.money-table', [
            'title' => __('Top customers'),
            'headers' => [__('Customer'), __('Orders'), __('Revenue')],
            'rows' => $byCustomer,
            'empty' => __('No customer purchase in this period.'),
            'columns' => [
                fn ($r) => $r->customer_name,
                fn ($r) => number_format($r->orders),
                fn ($r) => \App\Services\Money::format($r->revenue),
            ],
        ])
    </div>
</x-app-layout>