<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ __('Profit Report') }}</h2>
                <p class="truncate text-sm text-gray-500">{{ $period->label() }}</p>
            </div>
            <a href="{{ route('reports.index') }}" class="text-sm text-gray-600 underline">{{ __('All reports') }}</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        <x-report-filters :filters="$filters" :reset-url="route('reports.profit')" />

        {{-- Waterfall: revenue → COGS → gross profit → expenses → net profit --}}
        <div class="rounded-lg bg-white p-5 shadow">
            <h3 class="mb-4 text-sm font-semibold text-gray-800">{{ __('Profit &amp; loss') }}</h3>
            <dl class="space-y-2 text-sm">
                @foreach ([
                    ['label' => __('Net revenue'), 'value' => $current['revenue'], 'sub' => $previous['revenue']],
                    ['label' => __('Cost of goods sold'), 'value' => -$current['cogs'], 'sub' => -$previous['cogs'], 'subtle' => true],
                    ['label' => __('Gross profit'), 'value' => $current['gross_profit'], 'sub' => $previous['gross_profit'], 'strong' => true],
                    ['label' => __('Operating expenses'), 'value' => -$current['expenses'], 'sub' => -$previous['expenses'], 'subtle' => true],
                    ['label' => __('Net profit'), 'value' => $current['net_profit'], 'sub' => $previous['net_profit'], 'strong' => true],
                ] as $line)
                    <div class="flex items-center justify-between gap-4 border-b border-gray-100 py-2 last:border-0
                                {{ ($line['subtle'] ?? false) ? 'text-gray-500' : 'text-gray-900' }}
                                {{ ($line['strong'] ?? false) ? 'font-semibold' : '' }}">
                        <dt>{{ $line['label'] }}</dt>
                        <dd class="flex items-center gap-4 tabular-nums">
                            <span class="text-xs text-gray-400">{{ \App\Services\Money::format($line['sub']) }}</span>
                            <span class="w-32 text-right">{{ \App\Services\Money::format($line['value']) }}</span>
                        </dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-3 text-xs text-gray-400">{{ __('The grey figure is the same measure for the previous period.') }}</p>
        </div>

        <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
            <x-kpi-card :label="__('Gross margin')" :value="$current['gross_margin'].'%'" />
            <x-kpi-card :label="__('Net margin')" :value="$current['net_margin'].'%'"
                        :tone="$current['net_margin'] >= 0 ? 'positive' : 'negative'" />
            <x-kpi-card :label="__('Revenue')" :value="\App\Services\Money::format($current['revenue'])" />
            <x-kpi-card :label="__('Net profit')" :value="\App\Services\Money::format($current['net_profit'])"
                        :tone="$current['net_profit'] >= 0 ? 'positive' : 'negative'" />
        </div>

        @include('reports.partials.money-table', [
            'title' => __('Expenses by category'),
            'headers' => [__('Category'), __('Entries'), __('Amount')],
            'rows' => $expenses,
            'empty' => __('No expense recorded in this period.'),
            'columns' => [
                fn ($r) => $r->category_name,
                fn ($r) => number_format($r->entries),
                fn ($r) => \App\Services\Money::format($r->amount),
            ],
        ])
    </div>
</x-app-layout>