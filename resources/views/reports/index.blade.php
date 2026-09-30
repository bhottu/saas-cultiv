<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ __('Advanced Reports') }}</h2>
                <p class="truncate text-sm text-gray-500">{{ __('Detailed breakdowns across sales, inventory, purchasing and customers.') }}</p>
            </div>
            <a href="{{ route('analytics.index') }}"
               class="inline-flex shrink-0 items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                {{ __('Open Analytics') }}
            </a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ([
                ['route' => 'reports.sales',     'title' => 'Sales Report',     'desc' => 'Revenue, discounts, refunds and net sales by product, category, brand, customer, channel and staff.'],
                ['route' => 'reports.inventory', 'title' => 'Inventory Report', 'desc' => 'Stock value, low stock, incoming/outgoing units and every stock movement in the window.'],
                ['route' => 'reports.purchases', 'title' => 'Purchase Report',  'desc' => 'Purchase value by supplier and product, with received vs outstanding orders.'],
                ['route' => 'reports.customers', 'title' => 'Customer Report',  'desc' => 'Top customers, repeat purchase rate, new customers and average spend per order.'],
                ['route' => 'reports.profit',    'title' => 'Profit Report',    'desc' => 'Revenue, COGS, gross profit, expenses and net profit with margins.'],
            ] as $card)
                <a href="{{ route($card['route']) }}"
                   class="block rounded-lg bg-white p-5 shadow transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                    <h3 class="font-semibold text-gray-900">{{ __($card['title']) }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __($card['desc']) }}</p>
                    <span class="mt-3 inline-block text-sm font-medium text-indigo-600">{{ __('Open report') }} →</span>
                </a>
            @endforeach

            <a href="{{ route('sales.report') }}"
               class="block rounded-lg border border-dashed border-gray-300 bg-white p-5 transition hover:border-indigo-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                <h3 class="font-semibold text-gray-900">{{ __('Basic Sales Report') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('The original sales report — available on every plan.') }}</p>
                <span class="mt-3 inline-block text-sm font-medium text-gray-600">{{ __('Open report') }} →</span>
            </a>
        </div>
    </div>
</x-app-layout>