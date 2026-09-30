<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ __('Sales Report') }}</h2>
                <p class="truncate text-sm text-gray-500">{{ $period->label() }}</p>
            </div>
            <a href="{{ route('reports.index') }}" class="text-sm text-gray-600 underline">{{ __('All reports') }}</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        <x-report-filters :filters="$filters" :reset-url="route('reports.sales')" />

        <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
            <x-kpi-card :label="__('Net sales')" :value="\App\Services\Money::format($current['net_sales'])" />
            <x-kpi-card :label="__('Orders')" :value="$current['orders']" />
            <x-kpi-card :label="__('Average order value')" :value="\App\Services\Money::format($current['avg_order_value'])" />
            <x-kpi-card :label="__('Gross profit')"
                        :value="\App\Services\Money::format($current['gross_profit'])"
                        :tone="$current['gross_profit'] >= 0 ? 'positive' : 'negative'" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
            <x-kpi-card :label="__('Gross sales')" :value="\App\Services\Money::format($current['gross_sales'])" />
            <x-kpi-card :label="__('Discounts')" :value="\App\Services\Money::format($current['discount'])" />
            <x-kpi-card :label="__('Tax')" :value="\App\Services\Money::format($current['tax'])" />
            <x-kpi-card :label="__('Returns')" :value="\App\Services\Money::format($current['refunds'])" />
        </div>


        <div class="grid gap-6 lg:grid-cols-2">
            @include('reports.partials.money-table', [
                'title' => __('By product'),
                'headers' => [__('Product'), __('Qty'), __('Revenue'), __('COGS'), __('Profit'), __('Margin')],
                'rows' => $byProduct,
                'columns' => [
                    fn ($r) => $r->product_name . ($r->sku ? " ({$r->sku})" : ''),
                    fn ($r) => number_format($r->quantity),
                    fn ($r) => \App\Services\Money::format($r->revenue),
                    fn ($r) => \App\Services\Money::format($r->cogs),
                    fn ($r) => \App\Services\Money::format($r->gross_profit),
                    fn ($r) => $r->margin.'%',
                ],
            ])

            @include('reports.partials.money-table', [
                'title' => __('By customer'),
                'headers' => [__('Customer'), __('Orders'), __('Revenue')],
                'rows' => $byCustomer,
                'columns' => [
                    fn ($r) => $r->customer_name,
                    fn ($r) => number_format($r->orders),
                    fn ($r) => \App\Services\Money::format($r->revenue),
                ],
            ])
        </div>

        @include('reports.partials.bar-chart', ['title' => __('Sales trend'), 'rows' => $trend, 'valueKey' => 'total', 'labelKey' => 'date'])

        <div class="grid gap-6 lg:grid-cols-3">
            @include('reports.partials.simple-table', [
                'title' => __('By category'),
                'headers' => [__('Category'), __('Qty'), __('Revenue')],
                'rows' => $byCategory,
                'columns' => [
                    fn ($r) => $r->category_name,
                    fn ($r) => number_format($r->quantity),
                    fn ($r) => \App\Services\Money::format($r->revenue),
                ],
            ])

            @include('reports.partials.simple-table', [
                'title' => __('By brand'),
                'headers' => [__('Brand'), __('Qty'), __('Revenue')],
                'rows' => $byBrand,
                'columns' => [
                    fn ($r) => $r->brand_name,
                    fn ($r) => number_format($r->quantity),
                    fn ($r) => \App\Services\Money::format($r->revenue),
                ],
            ])

            @include('reports.partials.simple-table', [
                'title' => __('By sales channel'),
                'headers' => [__('Channel'), __('Orders'), __('Revenue')],
                'rows' => $byChannel,
                'columns' => [
                    fn ($r) => $r->label,
                    fn ($r) => number_format($r->orders),
                    fn ($r) => \App\Services\Money::format($r->revenue),
                ],
            ])
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            @include('reports.partials.simple-table', [
                'title' => __('By staff member'),
                'headers' => [__('Staff'), __('Orders'), __('Revenue')],
                'rows' => $byUser,
                'columns' => [
                    fn ($r) => $r->user_name,
                    fn ($r) => number_format($r->orders),
                    fn ($r) => \App\Services\Money::format($r->revenue),
                ],
            ])

            @include('reports.partials.simple-table', [
                'title' => __('Slow moving products'),
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

        @include('reports.partials.simple-table', [
            'title' => __('Products with no sales'),
            'headers' => [__('Product'), __('SKU'), __('Price')],
            'rows' => $noSales,
            'empty' => __('Every active product sold in this period.'),
            'columns' => [
                fn ($r) => $r->name,
                fn ($r) => $r->sku ?: '—',
                fn ($r) => \App\Services\Money::format($r->selling_price),
            ],
        ])
    </div>
</x-app-layout>

