@php
    // Standalone printable invoice: intentionally outside the application shell so the printed
    // page contains the document only. Styling reuses the same Tailwind build as the app.
    $money = fn ($cents) => \App\Services\Money::format($cents);
    $paymentLabel = fn (string $status) => match ($status) {
        'partial' => __('Partially Paid'),
        'paid' => __('Paid'),
        'refunded' => __('Refunded'),
        default => __('Unpaid'),
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Cultiv One') }} - {{ config('app.tagline', 'The smarter way to manage your business') }}</title>
        <meta name="description" content="Invoice {{ $sale->invoice_number }} from Cultiv One">
        <meta name="theme-color" content="#4f46e5">
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <link rel="alternate icon" type="image/png" sizes="64x64" href="{{ asset('favicon-64.png') }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css'])

        <style>
            /* Only the invoice is printed — the toolbar never reaches paper. */
            @media print {
                .no-print { display: none !important; }
                body { background: #fff; }
            }
        </style>
    </head>
    <body class="bg-gray-100 font-sans antialiased">
        <div class="no-print sticky top-0 z-10 border-b border-gray-200 bg-white">
            <div class="mx-auto flex max-w-4xl items-center justify-between gap-3 px-4 py-3">
                <a href="{{ route('sales.show', $sale) }}" class="text-sm text-gray-600 underline">{{ __('Back to sale') }}</a>
                <button type="button" onclick="window.print()"
                        class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    {{ __('Print') }}
                </button>
            </div>
        </div>

        <div class="mx-auto my-6 max-w-4xl bg-white p-8 shadow print:my-0 print:max-w-none print:shadow-none">
            {{-- Header --}}
            <div class="flex flex-wrap items-start justify-between gap-6 border-b border-gray-200 pb-6">
                <div>
                    <div class="text-lg font-bold text-gray-900">{{ $tenant?->name ?? config('app.name', 'Cultiv One') }}</div>
                    @if ($sale->warehouse?->address)
                        <div class="mt-1 text-sm text-gray-500">{{ $sale->warehouse->address }}</div>
                    @endif
                    <div class="mt-1 text-sm text-gray-500">{{ __('Warehouse') }}: {{ $sale->warehouse?->name ?? '—' }}</div>
                </div>

                <div class="text-right">
                    <div class="text-xl font-bold uppercase tracking-wide text-gray-900">{{ __('Invoice') }}</div>
                    <div class="mt-1 text-sm text-gray-700">{{ $sale->invoice_number }}</div>
                    <div class="text-sm text-gray-500">{{ $sale->sold_at?->format('d M Y H:i') }}</div>
                </div>
            </div>

            {{-- Parties + status --}}
            <div class="grid gap-6 border-b border-gray-200 py-6 sm:grid-cols-3">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Billed to') }}</div>
                    <div class="mt-1 text-sm font-medium text-gray-800">
                        {{ $sale->customer?->name ?? __('Walk-in Customer') }}
                    </div>
                    @if ($sale->customer?->phone)
                        <div class="text-sm text-gray-500">{{ $sale->customer->phone }}</div>
                    @endif
                    @if ($sale->customer?->email)
                        <div class="text-sm text-gray-500">{{ $sale->customer->email }}</div>
                    @endif
                    @if ($sale->customer?->address)
                        <div class="text-sm text-gray-500">{{ $sale->customer->address }}</div>
                    @endif
                </div>

                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Sales channel') }}</div>
                    <div class="mt-1 text-sm text-gray-800">
                        {{ __(config('business.sales.channels')[$sale->sales_channel] ?? ucfirst($sale->sales_channel)) }}
                    </div>
                    <div class="mt-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Status') }}</div>
                    <div class="text-sm text-gray-800">
                        {{ __(ucfirst($sale->status)) }} · {{ $paymentLabel($sale->payment_status) }}
                    </div>
                </div>

                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Served by') }}</div>
                    <div class="mt-1 text-sm text-gray-800">{{ $sale->createdBy?->name ?? '—' }}</div>
                    @if ($sale->notes)
                        <div class="mt-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Notes') }}</div>
                        <div class="text-sm text-gray-600">{{ $sale->notes }}</div>
                    @endif
                </div>
            </div>

            {{-- Items --}}
            <table class="mt-6 w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="pb-2">{{ __('Product') }}</th>
                        <th class="pb-2 text-right">{{ __('Qty') }}</th>
                        <th class="pb-2 text-right">{{ __('Price') }}</th>
                        <th class="pb-2 text-right">{{ __('Discount') }}</th>
                        <th class="pb-2 text-right">{{ __('Subtotal') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sale->items as $item)
                        <tr class="border-b border-gray-100">
                            <td class="py-2">
                                <div class="text-gray-800">{{ $item->product_name }}</div>
                                <div class="text-xs text-gray-500">{{ $item->sku ?: '—' }}</div>
                            </td>
                            <td class="py-2 text-right">{{ $item->quantity }} {{ $item->unit }}</td>
                            <td class="whitespace-nowrap py-2 text-right">{{ $money($item->selling_price) }}</td>
                            <td class="whitespace-nowrap py-2 text-right">{{ $item->discount > 0 ? '- '.$money($item->discount) : '—' }}</td>
                            <td class="whitespace-nowrap py-2 text-right font-medium">{{ $money($item->subtotal) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- Totals --}}
            <div class="mt-6 flex flex-col items-end">
                <dl class="w-full max-w-xs space-y-1 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-600">{{ __('Subtotal') }}</dt>
                        <dd>{{ $money($sale->subtotal) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">{{ __('Discount') }}</dt>
                        <dd>- {{ $money($sale->totalDiscount()) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">{{ __('Tax') }} ({{ $sale->tax_percent }}%)</dt>
                        <dd>{{ $money($sale->tax) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">{{ __('Shipping') }}</dt>
                        <dd>{{ $money($sale->shipping) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-bold text-gray-900">
                        <dt>{{ __('Total') }}</dt>
                        <dd>{{ $money($sale->total) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">{{ __('Paid') }}</dt>
                        <dd>{{ $money($sale->paid_amount) }}</dd>
                    </div>
                    @if ($sale->change_amount > 0)
                        <div class="flex justify-between">
                            <dt class="text-gray-600">{{ __('Change') }}</dt>
                            <dd>{{ $money($sale->change_amount) }}</dd>
                        </div>
                    @endif
                    @if ($sale->balanceDue() > 0)
                        <div class="flex justify-between font-medium text-amber-700">
                            <dt>{{ __('Outstanding') }}</dt>
                            <dd>{{ $money($sale->balanceDue()) }}</dd>
                        </div>
                    @endif
                    @if ($sale->refunded_amount > 0)
                        <div class="flex justify-between font-medium text-amber-700">
                            <dt>{{ __('Refunded') }}</dt>
                            <dd>{{ $money($sale->refunded_amount) }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- Payments --}}
            <div class="mt-8 border-t border-gray-200 pt-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Payments') }}</div>
                <ul class="mt-2 space-y-1 text-sm text-gray-700">
                    @forelse ($sale->payments as $payment)
                        <li>
                            {{ __(config('business.sales.payment_methods')[$payment->method] ?? ucfirst($payment->method)) }}
                            — {{ $money($payment->amount) }}
                            <span class="text-gray-500">
                                ({{ $payment->paid_at?->format('d M Y H:i') }}@if ($payment->reference) · {{ $payment->reference }}@endif)
                            </span>
                        </li>
                    @empty
                        <li class="text-gray-500">{{ __('No payment recorded.') }}</li>
                    @endforelse
                </ul>
            </div>

            @if ($sale->returns->isNotEmpty())
                <div class="mt-6 border-t border-gray-200 pt-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Returns') }}</div>
                    <ul class="mt-2 space-y-1 text-sm text-gray-700">
                        @foreach ($sale->returns as $return)
                            <li>
                                {{ $return->return_number }} — {{ $money($return->refund_amount) }}
                                <span class="text-gray-500">({{ $return->returned_at?->format('d M Y') }} · {{ $return->reason }})</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-8 border-t border-gray-200 pt-4 text-center text-xs text-gray-500">
                {{ __('Thank you for your business.') }}
            </div>
        </div>
    </body>
</html>