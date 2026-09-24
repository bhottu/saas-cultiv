<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ __('Sales report') }}</h2>
                <p class="truncate text-sm text-gray-500">{{ $from }} — {{ $to }}</p>
            </div>

            <a href="{{ route('sales.dashboard') }}" class="shrink-0 text-sm text-gray-600 underline">{{ __('Sales dashboard') }}</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        {{-- Range --}}
        <form method="GET" action="{{ route('sales.report') }}" class="grid gap-4 rounded-lg bg-white p-6 shadow md:grid-cols-4">
            <div>
                <x-input-label for="range" :value="__('Period')" />
                <select id="range" name="range"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @foreach (['today' => __('Today'), 'yesterday' => __('Yesterday'), 'week' => __('This week'), 'month' => __('This month'), 'custom' => __('Custom range')] as $value => $label)
                        <option value="{{ $value }}" @selected($preset === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="from" :value="__('From')" />
                <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$from" />
            </div>

            <div>
                <x-input-label for="to" :value="__('To')" />
                <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$to" />
            </div>

            <div class="flex items-end gap-3">
                <button class="rounded-lg bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-700">{{ __('Apply') }}</button>
                <a href="{{ route('sales.report') }}" class="text-sm text-gray-500 underline">{{ __('Reset') }}</a>
            </div>
        </form>

        {{-- KPIs --}}
        <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['label' => __('Total sales'), 'value' => $report['total_sales']],
                ['label' => __('Total orders'), 'value' => null, 'count' => $report['orders']],
                ['label' => __('Average order value'), 'value' => $report['average_order']],
                ['label' => __('Total discount'), 'value' => $report['discount']],
                ['label' => __('Total tax'), 'value' => $report['tax']],
                ['label' => __('Total COGS'), 'value' => $report['cogs']],
                ['label' => __('Refunds'), 'value' => $report['refunds']],
                ['label' => __('Gross profit'), 'value' => $report['gross_profit']],
            ] as $card)
                <div class="rounded-lg bg-white p-5 shadow">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $card['label'] }}</div>
                    <div class="mt-1 text-lg font-bold text-gray-900">
                        {{ isset($card['count']) ? $card['count'] : \App\Services\Money::format($card['value']) }}
                    </div>
                </div>
            @endforeach
        </div>

        <p class="text-xs text-gray-500">
            {{ __('Gross profit uses the cost price stored on each sale line and excludes tax and shipping.') }}
        </p>

        {{-- Breakdown: products --}}
        <div class="overflow-x-auto rounded-lg bg-white shadow">
            <div class="border-b border-gray-100 px-5 py-4 text-sm font-semibold text-gray-800">{{ __('Sales by product') }}</div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-4 py-3">{{ __('Product') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Qty') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Revenue') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('COGS') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Profit') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($byProduct as $row)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="text-gray-800">{{ $row->product_name }}</div>
                                <div class="text-xs text-gray-500">{{ $row->sku ?: '—' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right">{{ (int) $row->quantity }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ \App\Services\Money::format((int) $row->revenue) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-gray-500">{{ \App\Services\Money::format((int) $row->cogs) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ \App\Services\Money::format((int) $row->revenue - (int) $row->cogs) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-gray-500">{{ __('No sales in this period.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Breakdowns: category / brand / customer / channel --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="overflow-x-auto rounded-lg bg-white shadow">
                <div class="border-b border-gray-100 px-5 py-4 text-sm font-semibold text-gray-800">{{ __('Sales by category') }}</div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3">{{ __('Category') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Qty') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Revenue') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($byCategory as $row)
                            <tr>
                                <td class="px-4 py-3">{{ $row->category_name ?? __('Uncategorised') }}</td>
                                <td class="px-4 py-3 text-right">{{ (int) $row->quantity }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">{{ \App\Services\Money::format((int) $row->revenue) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-6 text-gray-500">{{ __('No sales in this period.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded-lg bg-white shadow">
                <div class="border-b border-gray-100 px-5 py-4 text-sm font-semibold text-gray-800">{{ __('Sales by brand') }}</div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3">{{ __('Brand') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Qty') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Revenue') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($byBrand as $row)
                            <tr>
                                <td class="px-4 py-3">{{ $row->brand_name ?? __('No brand') }}</td>
                                <td class="px-4 py-3 text-right">{{ (int) $row->quantity }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">{{ \App\Services\Money::format((int) $row->revenue) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-6 text-gray-500">{{ __('No sales in this period.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded-lg bg-white shadow">
                <div class="border-b border-gray-100 px-5 py-4 text-sm font-semibold text-gray-800">{{ __('Sales by customer') }}</div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3">{{ __('Customer') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Orders') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($byCustomer as $row)
                            <tr>
                                <td class="px-4 py-3">{{ $row->customer_name ?? __('Walk-in Customer') }}</td>
                                <td class="px-4 py-3 text-right">{{ (int) $row->orders }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">{{ \App\Services\Money::format((int) $row->total) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-6 text-gray-500">{{ __('No sales in this period.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded-lg bg-white shadow">
                <div class="border-b border-gray-100 px-5 py-4 text-sm font-semibold text-gray-800">{{ __('Sales by channel') }}</div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3">{{ __('Channel') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Orders') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($byChannel as $row)
                            <tr>
                                <td class="px-4 py-3">{{ __(config('business.sales.channels')[$row->sales_channel] ?? ucfirst($row->sales_channel)) }}</td>
                                <td class="px-4 py-3 text-right">{{ (int) $row->orders }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">{{ \App\Services\Money::format((int) $row->total) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-6 text-gray-500">{{ __('No sales in this period.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>