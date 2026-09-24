@php
    $summary = $dashboardData['summary'];
    $series = $dashboardData['series'];
    $maxDayTotal = $dashboardData['maxDayTotal'];
    $topProducts = $dashboardData['topProducts'];
    $recentSales = $dashboardData['recentSales'];
@endphp

    <div class="space-y-6" data-sales-summary>
        {{-- KPI cards --}}
        <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Sales today') }}</div>
                <div class="mt-1 text-lg font-bold text-gray-900">{{ \App\Services\Money::format($summary['sales_today']) }}</div>
                <div class="mt-1 text-sm text-gray-500">{{ trans_choice(':count order|:count orders', $summary['orders_today'], ['count' => $summary['orders_today']]) }}</div>
            </div>

            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Sales this month') }}</div>
                <div class="mt-1 text-lg font-bold text-gray-900">{{ \App\Services\Money::format($summary['sales_month']) }}</div>
                <div class="mt-1 text-sm text-gray-500">{{ trans_choice(':count order|:count orders', $summary['orders_month'], ['count' => $summary['orders_month']]) }}</div>
            </div>

            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Gross profit (this month)') }}</div>
                <div class="mt-1 text-lg font-bold {{ $summary['gross_profit'] >= 0 ? 'text-green-700' : 'text-red-700' }}">
                    {{ \App\Services\Money::format($summary['gross_profit']) }}
                </div>
                <div class="mt-1 text-sm text-gray-500">{{ __('Revenue minus cost of goods sold') }}</div>
            </div>

            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Refunds (this month)') }}</div>
                <div class="mt-1 text-lg font-bold text-gray-900">{{ \App\Services\Money::format($summary['refunded_month']) }}</div>
                <a href="{{ route('sales.returns') }}" class="mt-1 inline-block text-sm text-indigo-600 underline">{{ __('View returns') }}</a>
            </div>
        </div>

        {{-- Order health --}}
        <div class="grid gap-6 sm:grid-cols-3">
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Paid orders') }}</div>
                <div class="mt-1 text-lg font-bold text-green-700">{{ $summary['paid_orders'] }}</div>
            </div>

            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Pending / unpaid orders') }}</div>
                <div class="mt-1 text-lg font-bold text-amber-600">{{ $summary['unpaid_orders'] }}</div>
            </div>

            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Cancelled orders') }}</div>
                <div class="mt-1 text-lg font-bold text-red-700">{{ $summary['cancelled_orders'] }}</div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Sales trend (CSS bars — no chart dependency is added to the project) --}}
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-sm font-semibold text-gray-800">{{ __('Sales, last 7 days') }}</div>

                <div class="mt-4 flex items-end justify-between gap-2 sm:gap-4">
                    @foreach ($series as $day)
                        <div class="flex flex-1 flex-col items-center gap-1">
                            <div class="text-xs text-gray-500">{{ \App\Services\Money::format($day['total']) }}</div>
                            <div class="flex h-32 w-full items-end rounded bg-gray-100">
                                <div class="w-full rounded bg-indigo-500"
                                     style="height: {{ max(2, (int) round($day['total'] / $maxDayTotal * 100)) }}%"></div>
                            </div>
                            <div class="text-xs font-medium text-gray-600">{{ $day['label'] }}</div>
                            <div class="text-xs text-gray-400">{{ $day['orders'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Top selling products --}}
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="flex items-center justify-between">
                    <div class="text-sm font-semibold text-gray-800">{{ __('Top selling products (this month)') }}</div>
                    <a href="{{ route('sales.report') }}" class="text-sm text-indigo-600 underline">{{ __('Report') }}</a>
                </div>

                <div class="mt-4 space-y-3">
                    @forelse ($topProducts as $row)
                        @php
                            $maxQuantity = max(1, (int) $topProducts->max('quantity'));
                            $revenue = (int) $row->revenue;
                            $profit = $revenue - (int) $row->cogs;
                        @endphp
                        <div>
                            <div class="flex items-center justify-between gap-2 text-sm">
                                <span class="truncate text-gray-800">{{ $row->product_name }}</span>
                                <span class="shrink-0 text-gray-500">{{ (int) $row->quantity }} pcs</span>
                            </div>
                            <div class="mt-1 h-2 rounded bg-gray-100">
                                <div class="h-2 rounded bg-indigo-500" style="width: {{ max(3, (int) round((int) $row->quantity / $maxQuantity * 100)) }}%"></div>
                            </div>
                            <div class="mt-1 text-xs text-gray-500">
                                {{ \App\Services\Money::format($revenue) }} · {{ __('profit') }} {{ \App\Services\Money::format($profit) }}
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('No sales this month yet.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Recent sales --}}
        <div class="overflow-x-auto rounded-lg bg-white shadow">
            <div class="border-b border-gray-100 px-5 py-4 text-sm font-semibold text-gray-800">{{ __('Recent sales') }}</div>

            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-4 py-3">{{ __('Invoice') }}</th>
                        <th class="px-4 py-3">{{ __('Customer') }}</th>
                        <th class="px-4 py-3">{{ __('Date') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($recentSales as $sale)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('sales.show', $sale) }}" class="font-medium text-indigo-700 hover:underline">
                                    {{ $sale->invoice_number }}
                                </a>
                            </td>
                            <td class="px-4 py-3">{{ $sale->customer?->name ?? __('Walk-in Customer') }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $sale->sold_at?->format('d M Y H:i') }}</td>
                            <td class="px-4 py-3">{{ __(ucfirst($sale->status)) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ \App\Services\Money::format($sale->total) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-gray-500">{{ __('No sales yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
    </div>
        </div>
