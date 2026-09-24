<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ $customer->name }}</h2>
                <p class="truncate text-sm text-gray-500">
                    {{ $customer->phone ?: '—' }} · {{ $customer->email ?: '—' }}
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('customers.index') }}" class="text-sm text-gray-600 underline">{{ __('Back to customers') }}</a>
                <a href="{{ route('customers.edit', $customer) }}"
                   class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    {{ __('Edit customer') }}
                </a>
            </div>
        </div>
    </x-slot>

    @php
        $lastPurchase = $customer->lastPurchaseAt();

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

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Total orders') }}</div>
                <div class="mt-1 text-lg font-bold text-gray-900">{{ $customer->totalOrders() }}</div>
            </div>

            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Total spent') }}</div>
                <div class="mt-1 text-lg font-bold text-gray-900">{{ \App\Services\Money::format($customer->totalSpent()) }}</div>
            </div>

            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Last purchase') }}</div>
                <div class="mt-1 text-sm font-medium text-gray-800">
                    {{ $lastPurchase ? $lastPurchase->format('d M Y H:i') : __('Never') }}
                </div>
            </div>

            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Credit limit') }}</div>
                <div class="mt-1 text-lg font-bold text-gray-900">{{ \App\Services\Money::format($customer->credit_limit) }}</div>
            </div>
        </div>

        <div class="grid gap-4 rounded-lg bg-white p-6 shadow sm:grid-cols-3">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Address') }}</div>
                <div class="mt-1 text-sm text-gray-700">{{ $customer->address ?: '—' }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Status') }}</div>
                <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs {{ $customer->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                    {{ $customer->is_active ? __('Active') : __('Inactive') }}
                </span>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Notes') }}</div>
                <div class="mt-1 text-sm text-gray-700">{{ $customer->notes ?: '—' }}</div>
            </div>
        </div>

        {{-- Purchase history --}}
        <div class="overflow-x-auto rounded-lg bg-white shadow">
            <div class="border-b border-gray-100 px-5 py-4 text-sm font-semibold text-gray-800">{{ __('Purchase history') }}</div>

            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-4 py-3">{{ __('Invoice') }}</th>
                        <th class="px-4 py-3">{{ __('Date') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Items') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($sales as $sale)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('sales.show', $sale) }}" class="font-medium text-indigo-700 hover:underline">
                                    {{ $sale->invoice_number }}
                                </a>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $sale->sold_at?->format('d M Y H:i') }}</td>
                            <td class="px-4 py-3 text-right">{{ (int) $sale->items->sum('quantity') }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs {{ $statusTone($sale->status) }}">
                                    {{ __(ucfirst($sale->status)) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ \App\Services\Money::format($sale->total) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-gray-500">{{ __('No purchase history yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $sales->links() }}</div>
    </div>
</x-app-layout>