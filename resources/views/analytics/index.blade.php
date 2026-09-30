<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ __('Advanced Analytics') }}</h2>
                <p class="truncate text-sm text-gray-500">{{ $period->label() }} · {{ __('compared to the previous period') }}</p>
            </div>
            <a href="{{ route('reports.index') }}" class="text-sm text-gray-600 underline">{{ __('All reports') }}</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        <x-report-filters :filters="$filters" :reset-url="route('analytics.index')" />

        @php
            // A null change means the previous period had no baseline to compare against,
            // so it is shown as "—" rather than an invented percentage.
            $delta = fn ($value) => $value === null
                ? __('no baseline')
                : ($value >= 0 ? '+'.$value.'%' : $value.'%');
        @endphp

        <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
            <x-kpi-card :label="__('Net sales')"
                        :value="\App\Services\Money::format($sales['net_sales'])"
                        :hint="__('vs previous: ').$delta($sales['net_sales_change'])" />
            <x-kpi-card :label="__('Orders')"
                        :value="$sales['orders']"
                        :hint="__('vs previous: ').$delta($sales['orders_change'])" />
            <x-kpi-card :label="__('Average order value')"
                        :value="\App\Services\Money::format($sales['avg_order_value'])"
                        :hint="__('vs previous: ').$delta($sales['avg_order_value_change'])" />
            <x-kpi-card :label="__('Gross profit')"
                        :value="\App\Services\Money::format($sales['gross_profit'])"
                        :tone="$sales['gross_profit'] >= 0 ? 'positive' : 'negative'"
                        :hint="__('vs previous: ').$delta($sales['gross_profit_change'])" />
        </div>

        @include('reports.partials.bar-chart', ['title' => __('Sales trend'), 'rows' => $trend, 'valueKey' => 'total', 'labelKey' => 'date'])

        <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
            <x-kpi-card :label="__('Net profit')" :value="\App\Services\Money::format($profit['net_profit'])"
                        :tone="$profit['net_profit'] >= 0 ? 'positive' : 'negative'" />
            <x-kpi-card :label="__('Net margin')" :value="$profit['net_margin'].'%'" />
            <x-kpi-card :label="__('Expenses')" :value="\App\Services\Money::format($profit['expenses'])" />
            <x-kpi-card :label="__('COGS')" :value="\App\Services\Money::format($profit['cogs'])" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
            <x-kpi-card :label="__('Buying customers')" :value="$customers['buying_customers']" />
            <x-kpi-card :label="__('Repeat rate')" :value="$customers['repeat_rate'].'%'" />
            <x-kpi-card :label="__('Stock value')" :value="\App\Services\Money::format($inventory['stock_value'])" />
            <x-kpi-card :label="__('Low stock products')" :value="$inventory['low_stock']"
                        :tone="$inventory['low_stock'] > 0 ? 'negative' : 'positive'" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <x-kpi-card :label="__('Stock in (units)')" :value="number_format($movements['in'])" />
            <x-kpi-card :label="__('Stock out (units)')" :value="number_format($movements['out'])" />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            @include('reports.partials.money-table', [
                'title' => __('Best sellers'),
                'headers' => [__('Product'), __('Qty'), __('Revenue'), __('Margin')],
                'rows' => $topProducts,
                'empty' => __('No sales in this period.'),
                'columns' => [
                    fn ($r) => $r->product_name,
                    fn ($r) => number_format($r->quantity),
                    fn ($r) => \App\Services\Money::format($r->revenue),
                    fn ($r) => $r->margin.'%',
                ],
            ])

            @include('reports.partials.money-table', [
                'title' => __('Slow moving'),
                'headers' => [__('Product'), __('Qty'), __('Revenue')],
                'rows' => $slowMoving,
                'empty' => __('No sales in this period.'),
                'columns' => [
                    fn ($r) => $r->product_name,
                    fn ($r) => number_format($r->quantity),
                    fn ($r) => \App\Services\Money::format($r->revenue),
                ],
            ])
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            @include('reports.partials.simple-table', [
                'title' => __('Sales by channel'),
                'headers' => [__('Channel'), __('Orders'), __('Revenue')],
                'rows' => $byChannel,
                'columns' => [
                    fn ($r) => $r->label,
                    fn ($r) => number_format($r->orders),
                    fn ($r) => \App\Services\Money::format($r->revenue),
                ],
            ])

            @include('reports.partials.money-table', [
                'title' => __('Low stock'),
                'headers' => [__('Product'), __('On hand'), __('Minimum')],
                'rows' => $lowStock,
                'empty' => __('No product is at or below its minimum stock.'),
                'columns' => [
                    fn ($r) => $r->product_name,
                    fn ($r) => number_format($r->quantity).' '.$r->unit,
                    fn ($r) => number_format($r->minimum_stock).' '.$r->unit,
                ],
            ])
        </div>
    </div>
</x-app-layout>