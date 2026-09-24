<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ __('Sales') }}</h2>
                <p class="truncate text-sm text-gray-500">{{ __('Every sale of this workspace, newest first.') }}</p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('sales.dashboard') }}" class="text-sm text-gray-600 underline">{{ __('Sales dashboard') }}</a>
                <a href="{{ route('sales.create') }}"
                   class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    {{ __('New sale') }}
                </a>
            </div>
        </div>
    </x-slot>

    @php
        $statusTone = fn (string $status) => match ($status) {
            'completed' => 'bg-green-100 text-green-700',
            'refunded' => 'bg-amber-100 text-amber-700',
            'cancelled' => 'bg-red-100 text-red-700',
            'pending', 'processing' => 'bg-blue-100 text-blue-700',
            default => 'bg-gray-100 text-gray-600',
        };

        $paymentLabel = fn (string $status) => match ($status) {
            'partial' => __('Partially Paid'),
            'paid' => __('Paid'),
            'refunded' => __('Refunded'),
            default => __('Unpaid'),
        };

        $paymentTone = fn (string $status) => match ($status) {
            'paid' => 'bg-green-100 text-green-700',
            'partial' => 'bg-amber-100 text-amber-700',
            'refunded' => 'bg-purple-100 text-purple-700',
            default => 'bg-red-100 text-red-700',
        };
    @endphp

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        @if (session('status'))
            @php $status = session('status'); @endphp
            <div class="rounded-lg p-3 {{ ($status['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                {{ $status['message'] ?? '' }}
            </div>
        @endif

        {{-- Filters --}}
        <form method="GET" action="{{ route('sales.index') }}" class="grid gap-4 rounded-lg bg-white p-6 shadow md:grid-cols-4">
            <div class="md:col-span-2">
                <x-input-label for="search" :value="__('Search')" />
                <x-text-input id="search" name="search" type="text" class="mt-1 block w-full"
                              :value="$filters['search'] ?? ''"
                              placeholder="{{ __('Invoice number or customer') }}" />
            </div>

            <div>
                <x-input-label for="customer_id" :value="__('Customer')" />
                <select id="customer_id" name="customer_id"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">{{ __('All customers') }}</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected((string) ($filters['customer_id'] ?? '') === (string) $customer->id)>
                            {{ $customer->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="sales_channel" :value="__('Sales channel')" />
                <select id="sales_channel" name="sales_channel"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">{{ __('All channels') }}</option>
                    @foreach ($channels as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['sales_channel'] ?? '') === $value)>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="status" :value="__('Order status')" />
                <select id="status" name="status"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __(ucfirst($status)) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="payment_status" :value="__('Payment status')" />
                <select id="payment_status" name="payment_status"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">{{ __('All payments') }}</option>
                    @foreach ($paymentStatuses as $status)
                        <option value="{{ $status }}" @selected(($filters['payment_status'] ?? '') === $status)>{{ $paymentLabel($status) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="from" :value="__('From date')" />
                <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$filters['from'] ?? ''" />
            </div>

            <div>
                <x-input-label for="to" :value="__('To date')" />
                <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$filters['to'] ?? ''" />
            </div>

            <div class="flex items-center gap-3 md:col-span-4">
                <button class="rounded-lg bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-700">{{ __('Filter') }}</button>
                <a href="{{ route('sales.index') }}" class="text-sm text-gray-500 underline">{{ __('Reset') }}</a>
            </div>
        </form>

        {{-- Listing --}}
        <div class="overflow-x-auto rounded-lg bg-white shadow">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-4 py-3">{{ __('Invoice') }}</th>
                        <th class="px-4 py-3">{{ __('Customer') }}</th>
                        <th class="px-4 py-3">{{ __('Date') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Items') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Total') }}</th>
                        <th class="px-4 py-3">{{ __('Payment') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Channel') }}</th>
                        <th class="px-4 py-3">{{ __('Created by') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($sales as $sale)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('sales.show', $sale) }}" class="font-medium text-indigo-700 hover:underline">
                                    {{ $sale->invoice_number }}
                                </a>
                                @if ($sale->refunded_amount > 0)
                                    <div class="text-xs text-amber-600">
                                        {{ __('Refunded') }} {{ \App\Services\Money::format($sale->refunded_amount) }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                {{ $sale->customer?->name ?? __('Walk-in Customer') }}
                                @if ($sale->customer?->phone)
                                    <div class="text-xs text-gray-500">{{ $sale->customer->phone }}</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $sale->sold_at?->format('d M Y H:i') }}</td>
                            <td class="px-4 py-3 text-right">{{ $sale->items_count }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ \App\Services\Money::format($sale->total) }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs {{ $paymentTone($sale->payment_status) }}">
                                    {{ $paymentLabel($sale->payment_status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs {{ $statusTone($sale->status) }}">
                                    {{ __(ucfirst($sale->status)) }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ __($channels[$sale->sales_channel] ?? ucfirst($sale->sales_channel)) }}</td>
                            <td class="px-4 py-3">{{ $sale->createdBy?->name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-3">
                                    <a href="{{ route('sales.show', $sale) }}" class="text-indigo-600 hover:underline">{{ __('View') }}</a>
                                    <a href="{{ route('sales.print', $sale) }}" target="_blank" rel="noopener" class="text-gray-600 hover:underline">{{ __('Print') }}</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-6 text-gray-500">
                                {{ __('No sales yet.') }}
                                <a href="{{ route('sales.create') }}" class="text-indigo-600 underline">{{ __('Record the first sale') }}</a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $sales->links() }}</div>
    </div>
</x-app-layout>