<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $product->name }}</h2>
            <div class="flex items-center gap-3">
                <a href="{{ route('products.edit', $product) }}"
                   class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">{{ __('Edit') }}</a>
                <a href="{{ route('products.index') }}" class="text-sm text-gray-600 underline">{{ __('Back to products') }}</a>
            </div>
        </div>
    </x-slot>

    <div class="py-12 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if (session('status'))
            @php $status = session('status'); @endphp
            <div class="p-3 rounded {{ ($status['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                {{ $status['message'] ?? '' }}
            </div>
        @endif

        {{-- Summary --}}
        <div class="bg-white shadow rounded-lg p-6 grid gap-6 md:grid-cols-3">
            <div>
                <div class="text-sm text-gray-500">{{ __('Status') }}</div>
                <div class="mt-1">
                    <span class="px-2 py-0.5 rounded-full text-xs {{ $product->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                        {{ $product->is_active ? __('Active') : __('Inactive') }}
                    </span>
                </div>
                <div class="text-sm text-gray-500 mt-4">{{ __('SKU') }}</div>
                <div class="font-mono text-sm">{{ $product->sku ?: '—' }}</div>
                <div class="text-sm text-gray-500 mt-4">{{ __('Barcode') }}</div>
                <div class="font-mono text-sm">{{ $product->barcode ?: '—' }}</div>
            </div>

            <div>
                <div class="text-sm text-gray-500">{{ __('Category') }}</div>
                <div>{{ $product->category?->name ?? '—' }}</div>
                <div class="text-sm text-gray-500 mt-4">{{ __('Brand') }}</div>
                <div>{{ $product->brand?->name ?? '—' }}</div>
                <div class="text-sm text-gray-500 mt-4">{{ __('Unit') }}</div>
                <div>{{ $product->unit }}</div>
                <div class="text-sm text-gray-500 mt-4">{{ __('Track inventory') }}</div>
                <div>{{ $product->track_inventory ? __('Yes') : __('No') }}</div>
            </div>

            <div>
                <div class="text-sm text-gray-500">{{ __('Purchase price') }}</div>
                <div>{{ \App\Services\Money::format($product->purchase_price) }}</div>
                <div class="text-sm text-gray-500 mt-4">{{ __('Cost price') }}</div>
                <div>{{ \App\Services\Money::format($product->cost_price) }}</div>
                <div class="text-sm text-gray-500 mt-4">{{ __('Selling price') }}</div>
                <div class="text-lg font-semibold">{{ \App\Services\Money::format($product->selling_price) }}</div>
                <div class="text-sm text-gray-500 mt-4">{{ __('Minimum stock') }}</div>
                <div>
                    {{ $product->minimum_stock }}
                    @if ($product->max_stock) <span class="text-gray-400">/ {{ $product->max_stock }}</span> @endif
                </div>
            </div>

            @if ($product->description)
                <div class="md:col-span-3">
                    <div class="text-sm text-gray-500">{{ __('Description') }}</div>
                    <p class="text-sm whitespace-pre-line">{{ $product->description }}</p>
                </div>
            @endif
        </div>

        {{-- Stock on hand --}}
        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="font-semibold mb-3">{{ __('Stock on hand') }}</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-gray-200">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="py-2 pr-4">{{ __('Warehouse') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Quantity') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Incoming') }}</th>
                        <th class="py-2 text-right">{{ __('Outgoing') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($stockBalances as $balance)
                        <tr>
                            <td class="py-2 pr-4">{{ $balance->warehouse?->name ?? '—' }}</td>
                            <td class="py-2 pr-4 text-right">{{ $balance->quantity }}</td>
                            <td class="py-2 pr-4 text-right">{{ $balance->incoming }}</td>
                            <td class="py-2 text-right">{{ $balance->outgoing }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-gray-500">{{ __('No stock recorded yet.') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($stockBalances->isNotEmpty())
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="py-2 pr-4">{{ __('Total') }}</td>
                            <td class="py-2 pr-4 text-right">{{ $stockBalances->sum('quantity') }}</td>
                            <td class="py-2 pr-4 text-right">{{ $stockBalances->sum('incoming') }}</td>
                            <td class="py-2 text-right">{{ $stockBalances->sum('outgoing') }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
            </div>
        </div>

        {{-- Recent movements --}}
        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="font-semibold mb-3">{{ __('Recent stock movements') }}</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-gray-200">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="py-2 pr-4">{{ __('Date') }}</th>
                        <th class="py-2 pr-4">{{ __('Type') }}</th>
                        <th class="py-2 pr-4">{{ __('Warehouse') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Quantity') }}</th>
                        <th class="py-2">{{ __('Notes') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($recentMovements as $movement)
                        <tr>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $movement->created_at?->format('d M Y H:i') ?? '—' }}</td>
                            <td class="py-2 pr-4">{{ ucfirst((string) $movement->type) }}</td>
                            <td class="py-2 pr-4">{{ $movement->warehouse?->name ?? '—' }}</td>
                            <td class="py-2 pr-4 text-right">{{ $movement->quantity }}</td>
                            <td class="py-2 text-gray-600">{{ $movement->notes ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-gray-500">{{ __('No movements yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>

        {{-- Danger zone --}}
        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="font-semibold mb-3">{{ __('Delete product') }}</h3>
            <p class="text-sm text-gray-500 mb-3">{{ __('Products with stock on hand cannot be deleted.') }}</p>
            <form method="POST" action="{{ route('products.destroy', $product) }}"
                  onsubmit="return confirm('Delete this product?')">
                @csrf
                @method('DELETE')
                <x-danger-button>{{ __('Delete this product') }}</x-danger-button>
            </form>
        </div>
    </div>
</x-app-layout>