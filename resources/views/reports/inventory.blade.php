<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ __('Inventory Report') }}</h2>
                <p class="truncate text-sm text-gray-500">{{ $period->label() }}</p>
            </div>
            <a href="{{ route('reports.index') }}" class="text-sm text-gray-600 underline">{{ __('All reports') }}</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        <x-report-filters :filters="$filters" :reset-url="route('reports.inventory')" />

        <div class="grid gap-6 sm:grid-cols-3">
            <x-kpi-card :label="__('Stock value (at cost)')" :value="\App\Services\Money::format($summary['stock_value'])" />
            <x-kpi-card :label="__('Units on hand')" :value="number_format($summary['units'])" />
            <x-kpi-card :label="__('Low stock products')" :value="$summary['low_stock']"
                        :tone="$summary['low_stock'] > 0 ? 'negative' : 'positive'" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <x-kpi-card :label="__('Stock in (units)')" :value="number_format($movements['in'])" />
            <x-kpi-card :label="__('Stock out (units)')" :value="number_format($movements['out'])" />
        </div>

        @include('reports.partials.money-table', [
            'title' => __('Low stock products'),
            'headers' => [__('Product'), __('Warehouse'), __('On hand'), __('Minimum')],
            'rows' => $lowStock,
            'empty' => __('No product is at or below its minimum stock.'),
            'columns' => [
                fn ($r) => $r->product_name . ($r->sku ? " ({$r->sku})" : ''),
                fn ($r) => $r->warehouse_name,
                fn ($r) => number_format($r->quantity).' '.$r->unit,
                fn ($r) => number_format($r->minimum_stock).' '.$r->unit,
            ],
        ])

        <div class="overflow-x-auto rounded-lg bg-white shadow">
            <div class="border-b border-gray-100 px-5 py-4 text-sm font-semibold text-gray-800">{{ __('Stock movements') }}</div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3">{{ __('When') }}</th>
                            <th class="px-4 py-3">{{ __('Product') }}</th>
                            <th class="px-4 py-3">{{ __('Warehouse') }}</th>
                            <th class="px-4 py-3">{{ __('Type') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Quantity') }}</th>
                            <th class="px-4 py-3">{{ __('Reference') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($log as $movement)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ $movement->created_at?->format('d M Y H:i') }}</td>
                                <td class="px-4 py-3 text-gray-800">{{ $movement->product?->name ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $movement->warehouse?->name ?? '—' }}</td>
                                <td class="px-4 py-3">{{ str_replace('_', ' ', $movement->type) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ number_format($movement->quantity) }}</td>
                                <td class="px-4 py-3 text-xs text-gray-500">{{ $movement->reference_type ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-gray-500">{{ __('No stock movement in this period.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>