<x-app-layout>
    <x-slot name="header"><div class="flex items-center justify-between gap-3"><h2 class="text-lg font-semibold text-gray-900">{{ __('Purchases') }}</h2><a href="{{ route('purchases.create') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white">{{ __('New purchase') }}</a></div></x-slot>
    @php
        // Badge colours copied from sales/index so purchase states read the same as sale
        // states. Visual only — the label itself is still the same value as before.
        $statusTone = fn (string $status) => match ($status) {
            'received' => 'bg-green-100 text-green-700',
            'ordered' => 'bg-blue-100 text-blue-700',
            'cancelled' => 'bg-red-100 text-red-700',
            default => 'bg-gray-100 text-gray-600',
        };
    @endphp

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        @if (session('status'))
            @php $status = session('status'); @endphp
            <div class="rounded-lg p-3 {{ ($status['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                {{ $status['message'] ?? '' }}
            </div>
        @endif

        {{-- Filters: labels, focus rings and the Filter/Reset row are lifted from sales/index.
             The Filter control used to be a bare <x-primary-button>, which as a grid item
             stretched across the whole column — hence the oversized button. --}}
        <form method="GET" action="{{ route('purchases.index') }}" class="grid gap-4 rounded-lg bg-white p-6 shadow md:grid-cols-4">
            <div>
                <x-input-label for="status" :value="__('Status')" />
                <select id="status" name="status"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="supplier_id" :value="__('Supplier')" />
                <select id="supplier_id" name="supplier_id"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">{{ __('All suppliers') }}</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((string) ($filters['supplier_id'] ?? '') === (string) $supplier->id)>{{ $supplier->name }}</option>
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
                <a href="{{ route('purchases.index') }}" class="text-sm text-gray-500 underline">{{ __('Reset') }}</a>
            </div>
        </form>

        {{-- Listing: identical wrapper, table, head row and body classes to sales/index.
             The horizontal scroll container is what keeps this usable on mobile. --}}
        <div class="overflow-x-auto rounded-lg bg-white shadow">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-4 py-3">{{ __('Number') }}</th>
                        <th class="px-4 py-3">{{ __('Supplier') }}</th>
                        <th class="px-4 py-3">{{ __('Date') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Total') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($purchases as $purchase)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('purchases.show', $purchase) }}" class="font-medium text-indigo-700 hover:underline">
                                    {{ $purchase->invoice_number }}
                                </a>
                            </td>
                            <td class="px-4 py-3">{{ $purchase->supplier?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $purchase->ordered_at?->format('d M Y') }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs {{ $statusTone($purchase->status) }}">
                                    {{ ucfirst($purchase->status) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ \App\Services\Money::format($purchase->total) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-3">
                                    <a href="{{ route('purchases.show', $purchase) }}" class="text-indigo-600 hover:underline">{{ __('View') }}</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-gray-500">
                                {{ __('No purchases found.') }}
                                <a href="{{ route('purchases.create') }}" class="text-indigo-600 underline">{{ __('Create the first one') }}</a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $purchases->links() }}</div>
    </div>
</x-app-layout>