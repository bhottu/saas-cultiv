<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Inventory') }}</h2>
            <p class="text-sm text-gray-500">{{ __('Current stock is maintained by stock movements.') }}</p>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        @if (session('status'))
            <div class="rounded-lg p-3 {{ ($session['status']['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                {{ $session['status']['message'] ?? '' }}
            </div>
        @endif

        <div class="grid gap-4 rounded-lg bg-white p-6 shadow sm:grid-cols-3">
            <div><div class="text-sm text-gray-500">{{ __('Products tracked') }}</div><div class="text-2xl font-semibold">{{ $products->total() }}</div></div>
            <div><div class="text-sm text-gray-500">{{ __('Low stock') }}</div><div class="text-2xl font-semibold text-amber-600">{{ $lowStockCount }}</div></div>
            <div><div class="text-sm text-gray-500">{{ __('Warehouse') }}</div><div class="text-lg font-semibold">{{ $warehouse?->name ?? __('Not configured') }}</div></div>
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">{{ __('Product') }}</th>
                            <th class="px-4 py-3">{{ __('Current stock') }}</th>
                            <th class="px-4 py-3">{{ __('Minimum stock') }}</th>
                            <th class="px-4 py-3">{{ __('Maximum stock') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($products as $product)
                            @php($hasBalance = $balances->has($product->id))
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-800">{{ $product->name }}</td>
                                <td class="px-4 py-3">
                                    @if ($hasBalance)
                                        <span class="{{ $balances[$product->id] <= $product->minimum_stock ? 'text-amber-600' : 'text-green-600' }}">{{ $balances[$product->id] }} {{ $product->unit }}</span>
                                    @else
                                        <span class="text-amber-600">{{ __('No inventory record') }} · 0 {{ $product->unit }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $product->minimum_stock }} {{ $product->unit }}</td>
                                <td class="px-4 py-3">{{ $product->max_stock ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-gray-500">{{ __('No inventory-tracked products.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t px-4 py-3">{{ $products->links() }}</div>
        </div>

        @if ($products->isNotEmpty() && $warehouses->isNotEmpty())
            <div class="rounded-lg bg-white p-6 shadow">
                <h3 class="text-lg font-semibold text-gray-900">{{ __('Stock adjustment / opening balance') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('Use an adjustment to enter opening stock. Minimum and maximum stock are thresholds, not quantities.') }}</p>

                <form method="POST" action="{{ route('stock.adjust') }}" class="mt-5 grid gap-4 md:grid-cols-2">
                    @csrf
                    <div>
                        <x-input-label for="adjust_product_id" :value="__('Product *')" />
                        <select id="adjust_product_id" name="product_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="adjust_warehouse_id" :value="__('Warehouse *')" />
                        <select id="adjust_warehouse_id" name="warehouse_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($warehouses as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="adjust_direction" :value="__('Direction *')" />
                        <select id="adjust_direction" name="direction" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="in">{{ __('Stock in / opening balance') }}</option>
                            <option value="out">{{ __('Stock out / reduction') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="adjust_quantity" :value="__('Quantity *')" />
                        <x-text-input id="adjust_quantity" name="quantity" type="number" min="1" required class="mt-1 block w-full" />
                    </div>
                    <div class="md:col-span-2">
                        <x-input-label for="adjust_reason" :value="__('Reason *')" />
                        <x-text-input id="adjust_reason" name="reason" type="text" required class="mt-1 block w-full" placeholder="{{ __('Opening stock, damaged goods, stock count…') }}" />
                    </div>
                    <div class="md:col-span-2">
                        <x-input-label for="adjust_notes" :value="__('Notes (optional)')" />
                        <textarea id="adjust_notes" name="notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes') }}</textarea>
                    </div>
                    <div class="md:col-span-2"><x-primary-button>{{ __('Save adjustment') }}</x-primary-button></div>
                </form>
            </div>
        @endif
    </div>
</x-app-layout>

            </div>
            <div class="border-t px-4 py-3">{{ $products->links() }}</div>
        </div>
