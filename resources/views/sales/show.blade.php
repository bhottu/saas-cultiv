<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ $sale->invoice_number }}</h2>
                <p class="truncate text-sm text-gray-500">
                    {{ $sale->customer?->name ?? __('Walk-in Customer') }} · {{ $sale->sold_at?->format('d M Y H:i') }}
                </p>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <a href="{{ route('sales.index') }}" class="text-sm text-gray-600 underline">{{ __('Back to sales') }}</a>
                <a href="{{ route('sales.print', $sale) }}" target="_blank" rel="noopener"
                   class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ __('Print invoice') }}
                </a>
                @if ($sale->hasReturnableItems() && in_array($sale->status, [\App\Models\Sale::STATUS_COMPLETED, \App\Models\Sale::STATUS_REFUNDED], true))
                    <a href="{{ route('sales.return', $sale) }}"
                       class="inline-flex items-center rounded-lg border border-amber-500 px-4 py-2 text-sm font-medium text-amber-700 hover:bg-amber-50">
                        {{ __('Return / refund items') }}
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    @php
        $business = app(\App\Services\BusinessAuthorization::class);
        $canSeeProfit = $business->can('reports.view');

        $paymentLabel = fn (string $status) => match ($status) {
            'partial' => __('Partially Paid'),
            'paid' => __('Paid'),
            'refunded' => __('Refunded'),
            default => __('Unpaid'),
        };

        $statusTone = fn (string $status) => match ($status) {
            'completed' => 'bg-green-100 text-green-700',
            'refunded' => 'bg-amber-100 text-amber-700',
            'cancelled' => 'bg-red-100 text-red-700',
            default => 'bg-blue-100 text-blue-700',
        };
    @endphp

    <div class="mx-auto max-w-5xl space-y-6 py-12 sm:px-6 lg:px-8">
        @if (session('status'))
            @php $status = session('status'); @endphp
            <div class="rounded-lg p-3 {{ ($status['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                {{ $status['message'] ?? '' }}
            </div>
        @endif

        {{-- Document summary --}}
        <div class="grid gap-4 rounded-lg bg-white p-6 shadow sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Order status') }}</div>
                <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $statusTone($sale->status) }}">
                    {{ __(ucfirst($sale->status)) }}
                </span>
            </div>

            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Payment status') }}</div>
                <div class="mt-1 text-sm font-medium text-gray-800">{{ $paymentLabel($sale->payment_status) }}</div>
                <div class="text-xs text-gray-500">
                    {{ __('Paid') }} {{ \App\Services\Money::format($sale->paid_amount) }}
                    @if ($sale->refunded_amount > 0)
                        · {{ __('refunded') }} {{ \App\Services\Money::format($sale->refunded_amount) }}
                    @endif
                </div>
            </div>

            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Sales channel') }}</div>
                <div class="mt-1 text-sm font-medium text-gray-800">
                    {{ __(config('business.sales.channels')[$sale->sales_channel] ?? ucfirst($sale->sales_channel)) }}
                </div>
                <div class="text-xs text-gray-500">{{ $sale->warehouse?->name ?? '—' }}</div>
            </div>

            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Created by') }}</div>
                <div class="mt-1 text-sm font-medium text-gray-800">{{ $sale->createdBy?->name ?? '—' }}</div>
                @if ($sale->notes)
                    <div class="text-xs text-gray-500">{{ $sale->notes }}</div>
                @endif
            </div>
        </div>

        {{-- Items --}}
        <div class="overflow-x-auto rounded-lg bg-white shadow">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-4 py-3">{{ __('Product') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Qty') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Price') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Discount') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Subtotal') }}</th>
                        @if ($canSeeProfit)
                            <th class="px-4 py-3 text-right">{{ __('Cost (snapshot)') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Profit') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($sale->items as $item)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-800">{{ $item->product_name }}</div>
                                <div class="text-xs text-gray-500">
                                    {{ $item->sku ?: '—' }}
                                    @if ($item->returned_quantity > 0)
                                        · <span class="text-amber-600">{{ $item->returned_quantity }} {{ __('returned') }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right">{{ $item->quantity }} {{ $item->unit }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ \App\Services\Money::format($item->selling_price) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                {{ $item->discount > 0
                                    ? ($item->discount_type === 'percent'
                                        ? $item->discount_value.'% ('.\App\Services\Money::format($item->discount).')'
                                        : \App\Services\Money::format($item->discount))
                                    : '—' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ \App\Services\Money::format($item->subtotal) }}</td>
                            @if ($canSeeProfit)
                                <td class="whitespace-nowrap px-4 py-3 text-right text-gray-500">{{ \App\Services\Money::format($item->cost_price) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">{{ \App\Services\Money::format($item->profit()) }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Totals --}}
            <div class="rounded-lg bg-white p-6 shadow">
                <div class="text-sm font-semibold text-gray-800">{{ __('Totals') }}</div>

                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-600">{{ __('Subtotal') }}</dt>
                        <dd>{{ \App\Services\Money::format($sale->subtotal) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">{{ __('Item discounts') }}</dt>
                        <dd>- {{ \App\Services\Money::format($sale->item_discount) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">{{ __('Extra discount') }}</dt>
                        <dd>
                            - {{ \App\Services\Money::format($sale->discount) }}
                            @if ($sale->discount_type === 'percent' && $sale->discount_value > 0)
                                <span class="text-xs text-gray-500">({{ $sale->discount_value }}%)</span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">{{ __('Tax') }} ({{ $sale->tax_percent }}%)</dt>
                        <dd>{{ \App\Services\Money::format($sale->tax) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">{{ __('Shipping') }}</dt>
                        <dd>{{ \App\Services\Money::format($sale->shipping) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-gray-100 pt-2 text-base font-bold text-gray-900">
                        <dt>{{ __('Total') }}</dt>
                        <dd>{{ \App\Services\Money::format($sale->total) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">{{ __('Paid') }}</dt>
                        <dd>{{ \App\Services\Money::format($sale->paid_amount) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">{{ __('Change') }}</dt>
                        <dd>{{ \App\Services\Money::format($sale->change_amount) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">{{ __('Outstanding') }}</dt>
                        <dd class="font-medium text-amber-600">{{ \App\Services\Money::format($sale->balanceDue()) }}</dd>
                    </div>
                    @if ($sale->refunded_amount > 0)
                        <div class="flex justify-between">
                            <dt class="text-gray-600">{{ __('Refunded') }}</dt>
                            <dd class="font-medium text-amber-700">{{ \App\Services\Money::format($sale->refunded_amount) }}</dd>
                        </div>
                    @endif
                    @if ($canSeeProfit)
                        <div class="flex justify-between border-t border-gray-100 pt-2">
                            <dt class="text-gray-600">{{ __('COGS (snapshot)') }}</dt>
                            <dd>{{ \App\Services\Money::format($sale->total_cogs) }}</dd>
                        </div>
                        <div class="flex justify-between font-medium">
                            <dt class="text-gray-700">{{ __('Gross profit') }}</dt>
                            <dd class="{{ $sale->grossProfit() >= 0 ? 'text-green-700' : 'text-red-700' }}">
                                {{ \App\Services\Money::format($sale->grossProfit()) }}
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="space-y-6">
                {{-- Payments --}}
                <div class="rounded-lg bg-white p-6 shadow">
                    <div class="text-sm font-semibold text-gray-800">{{ __('Payments') }}</div>

                    <ul class="mt-3 divide-y divide-gray-100 text-sm">
                        @forelse ($sale->payments as $payment)
                            <li class="flex items-center justify-between gap-2 py-2">
                                <span>
                                    <span class="font-medium text-gray-800">{{ __(config('business.sales.payment_methods')[$payment->method] ?? ucfirst($payment->method)) }}</span>
                                    <span class="block text-xs text-gray-500">
                                        {{ $payment->paid_at?->format('d M Y H:i') }}
                                        @if ($payment->reference) · {{ $payment->reference }} @endif
                                        @if ($payment->createdBy) · {{ $payment->createdBy->name }} @endif
                                    </span>
                                </span>
                                <span class="shrink-0 font-medium">{{ \App\Services\Money::format($payment->amount) }}</span>
                            </li>
                        @empty
                            <li class="py-2 text-gray-500">{{ __('No payment recorded — this sale is unpaid.') }}</li>
                        @endforelse
                    </ul>
                </div>

                {{-- Returns --}}
                <div class="rounded-lg bg-white p-6 shadow">
                    <div class="flex items-center justify-between">
                        <div class="text-sm font-semibold text-gray-800">{{ __('Returns & refunds') }}</div>
                        <a href="{{ route('sales.returns') }}" class="text-sm text-indigo-600 underline">{{ __('All returns') }}</a>
                    </div>

                    <ul class="mt-3 divide-y divide-gray-100 text-sm">
                        @forelse ($sale->returns as $return)
                            <li class="py-2">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-medium text-gray-800">{{ $return->return_number }}</span>
                                    <span class="shrink-0 font-medium">{{ \App\Services\Money::format($return->refund_amount) }}</span>
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ $return->returned_at?->format('d M Y H:i') }} ·
                                    {{ trans_choice(':count unit|:count units', $return->items->sum('quantity'), ['count' => $return->items->sum('quantity')]) }} ·
                                    {{ $return->reason }}
                                </div>
                            </li>
                        @empty
                            <li class="py-2 text-gray-500">{{ __('Nothing returned yet.') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex flex-wrap items-center gap-3 rounded-lg bg-white p-6 shadow">
            @if (in_array($sale->status, [\App\Models\Sale::STATUS_PENDING, \App\Models\Sale::STATUS_DRAFT, \App\Models\Sale::STATUS_PROCESSING], true))
                <form method="POST" action="{{ route('sales.complete', $sale) }}">
                    @csrf
                    <x-primary-button>{{ __('Complete sale') }}</x-primary-button>
                </form>
            @endif

            @if (! in_array($sale->status, [\App\Models\Sale::STATUS_CANCELLED, \App\Models\Sale::STATUS_REFUNDED], true))
                <form method="POST" action="{{ route('sales.cancel', $sale) }}"
                      onsubmit="return confirm('Cancel this sale and restore its stock?')">
                    @csrf
                    <button class="rounded-md border border-red-500 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-red-600 hover:bg-red-50">
                        {{ __('Cancel sale') }}
                    </button>
                </form>
            @endif

            @if ($sale->status === \App\Models\Sale::STATUS_COMPLETED && $sale->hasReturnableItems())
                <form method="POST" action="{{ route('sales.refund', $sale) }}"
                      onsubmit="return confirm('Refund every remaining item of this sale?')">
                    @csrf
                    <button class="rounded-md border border-amber-500 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-amber-700 hover:bg-amber-50">
                        {{ __('Refund all') }}
                    </button>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>