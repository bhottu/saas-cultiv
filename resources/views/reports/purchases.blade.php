<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ __('Purchase Report') }}</h2>
                <p class="truncate text-sm text-gray-500">{{ $period->label() }}</p>
            </div>
            <a href="{{ route('reports.index') }}" class="text-sm text-gray-600 underline">{{ __('All reports') }}</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        <x-report-filters :filters="$filters" :reset-url="route('reports.purchases')" />

        <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
            <x-kpi-card :label="__('Purchase orders')" :value="$summary['orders']" />
            <x-kpi-card :label="__('Received orders')" :value="$summary['received']" />
            <x-kpi-card :label="__('Total spend')" :value="\App\Services\Money::format($summary['spend'])" />
            <x-kpi-card :label="__('Discounts')" :value="\App\Services\Money::format($summary['discount'])" />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            @include('reports.partials.money-table', [
                'title' => __('By supplier'),
                'headers' => [__('Supplier'), __('Orders'), __('Spend')],
                'rows' => $bySupplier,
                'empty' => __('No purchase in this period.'),
                'columns' => [
                    fn ($r) => $r->supplier_name,
                    fn ($r) => number_format($r->orders),
                    fn ($r) => \App\Services\Money::format($r->spend),
                ],
            ])

            @include('reports.partials.money-table', [
                'title' => __('By product'),
                'headers' => [__('Product'), __('Qty'), __('Spend')],
                'rows' => $byProduct,
                'empty' => __('No purchase in this period.'),
                'columns' => [
                    fn ($r) => $r->product_name . ($r->sku ? " ({$r->sku})" : ''),
                    fn ($r) => number_format($r->quantity),
                    fn ($r) => \App\Services\Money::format($r->spend),
                ],
            ])
        </div>
    </div>
</x-app-layout>