<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ __('Return items') }}</h2>
                <p class="truncate text-sm text-gray-500">
                    {{ $sale->invoice_number }} · {{ $sale->customer?->name ?? __('Walk-in Customer') }} ·
                    {{ $sale->sold_at?->format('d M Y') }}
                </p>
            </div>

            <a href="{{ route('sales.show', $sale) }}" class="shrink-0 text-sm text-gray-600 underline">{{ __('Back to sale') }}</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-6 py-12 sm:px-6 lg:px-8">
        @if ($errors->any())
            <div class="rounded-lg bg-red-100 p-3 text-red-800">
                <ul class="list-inside list-disc space-y-1 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('status'))
            @php $status = session('status'); @endphp
            <div class="rounded-lg p-3 {{ ($status['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                {{ $status['message'] ?? '' }}
            </div>
        @endif

        <form method="POST" action="{{ route('sales.return.store', $sale) }}" class="space-y-6">
            @csrf

            <div class="overflow-x-auto rounded-lg bg-white shadow">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3">{{ __('Product') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Sold') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Already returned') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Returnable') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Price (snapshot)') }}</th>
                            <th class="px-4 py-3">{{ __('Return now') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($sale->items as $item)
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-800">{{ $item->product_name }}</div>
                                    <div class="text-xs text-gray-500">{{ $item->sku ?: '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-right">{{ $item->quantity }} {{ $item->unit }}</td>
                                <td class="px-4 py-3 text-right">{{ $item->returned_quantity }}</td>
                                <td class="px-4 py-3 text-right font-medium">{{ $item->remainingQuantity() }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    {{ \App\Services\Money::format($item->selling_price) }}
                                    @if ($item->discount > 0)
                                        <div class="text-xs text-gray-500">
                                            {{ __('net') }} {{ \App\Services\Money::format((int) round($item->subtotal / max(1, $item->quantity))) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <input type="number" min="0" max="{{ $item->remainingQuantity() }}" step="1"
                                           name="quantities[{{ $item->id }}]" value="{{ old('quantities.'.$item->id, 0) }}"
                                           @disabled($item->remainingQuantity() === 0)
                                           class="block w-24 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="grid gap-4 rounded-lg bg-white p-6 shadow md:grid-cols-2">
                <div>
                    <x-input-label for="reason" :value="__('Reason')" />
                    <x-text-input id="reason" name="reason" type="text" class="mt-1 block w-full"
                                  :value="old('reason', 'Customer return')" required />
                    <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="refund_method" :value="__('Refund method')" />
                    <select id="refund_method" name="refund_method"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach ($paymentMethods as $value => $label)
                            <option value="{{ $value }}" @selected(old('refund_method', $sale->payment_method) === $value)>{{ __($label) }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('refund_method')" class="mt-2" />
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="notes" :value="__('Notes')" />
                    <textarea id="notes" name="notes" rows="2"
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes') }}</textarea>
                </div>

                <p class="text-xs text-gray-500 md:col-span-2">
                    {{ __('Returned units go back into stock as inventory movements and the refund is calculated from the original sale prices.') }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <x-primary-button>{{ __('Record return') }}</x-primary-button>
                <a href="{{ route('sales.show', $sale) }}" class="text-sm text-gray-600 underline">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
</x-app-layout>