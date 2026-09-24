<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ __('Sales returns') }}</h2>
                <p class="truncate text-sm text-gray-500">{{ __('Every refund is backed by an inventory movement.') }}</p>
            </div>

            <a href="{{ route('sales.index') }}" class="shrink-0 text-sm text-gray-600 underline">{{ __('Back to sales') }}</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        @if (session('status'))
            @php $status = session('status'); @endphp
            <div class="rounded-lg p-3 {{ ($status['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                {{ $status['message'] ?? '' }}
            </div>
        @endif

        <form method="GET" action="{{ route('sales.returns') }}" class="grid gap-4 rounded-lg bg-white p-6 shadow md:grid-cols-4">
            <div class="md:col-span-2">
                <x-input-label for="search" :value="__('Search')" />
                <x-text-input id="search" name="search" type="text" class="mt-1 block w-full"
                              :value="$filters['search'] ?? ''"
                              placeholder="{{ __('Return number, invoice number or reason') }}" />
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
                <a href="{{ route('sales.returns') }}" class="text-sm text-gray-500 underline">{{ __('Reset') }}</a>
            </div>
        </form>

        <div class="overflow-x-auto rounded-lg bg-white shadow">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-4 py-3">{{ __('Return') }}</th>
                        <th class="px-4 py-3">{{ __('Invoice') }}</th>
                        <th class="px-4 py-3">{{ __('Customer') }}</th>
                        <th class="px-4 py-3">{{ __('Date') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Units') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Refund') }}</th>
                        <th class="px-4 py-3">{{ __('Reason') }}</th>
                        <th class="px-4 py-3">{{ __('By') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($returns as $return)
                        <tr>
                            <td class="px-4 py-3 font-medium text-gray-800">{{ $return->return_number }}</td>
                            <td class="px-4 py-3">
                                @if ($return->sale)
                                    <a href="{{ route('sales.show', $return->sale) }}" class="text-indigo-700 hover:underline">
                                        {{ $return->sale->invoice_number }}
                                    </a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $return->sale?->customer?->name ?? __('Walk-in Customer') }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $return->returned_at?->format('d M Y H:i') }}</td>
                            <td class="px-4 py-3 text-right">{{ (int) $return->items->sum('quantity') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ \App\Services\Money::format($return->refund_amount) }}</td>
                            <td class="px-4 py-3">{{ $return->reason }}</td>
                            <td class="px-4 py-3">{{ $return->createdBy?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-6 text-gray-500">{{ __('No returns recorded yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $returns->links() }}</div>
    </div>
</x-app-layout>