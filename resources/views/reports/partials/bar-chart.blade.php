{{--
    Dependency-free bar chart. Rows must expose $valueKey and $labelKey; the bar width
    is a percentage of the largest value, so no JS charting library is required.
--}}
@props(['title', 'rows', 'valueKey' => 'total', 'labelKey' => 'date'])

@php
    $values = $rows->map(fn ($row) => (int) ($row[$valueKey] ?? 0));
    $max = max(1, (int) ($values->max() ?? 1));
@endphp

<div class="rounded-lg bg-white p-5 shadow">
    <h3 class="mb-4 text-sm font-semibold text-gray-800">{{ $title }}</h3>

    @if ($rows->isEmpty() || $values->sum() === 0)
        <p class="text-sm text-gray-500">{{ __('No data for this period.') }}</p>
    @else
        <div class="flex h-40 items-end gap-1 overflow-x-auto">
            @foreach ($rows as $row)
                @php $value = (int) ($row[$valueKey] ?? 0); @endphp
                <div class="flex min-w-[6px] flex-1 flex-col items-center justify-end gap-1"
                     title="{{ $row[$labelKey] ?? '' }}: {{ \App\Services\Money::format($value) }}">
                    <div class="w-full rounded-t bg-indigo-500"
                         style="height: {{ max(2, (int) round($value / $max * 150)) }}px"></div>
                </div>
            @endforeach
        </div>
        <div class="mt-2 flex justify-between text-xs text-gray-500">
            <span>{{ $rows->first()[$labelKey] ?? '' }}</span>
            <span>{{ \App\Services\Money::format((int) $values->sum()) }}</span>
            <span>{{ $rows->last()[$labelKey] ?? '' }}</span>
        </div>
    @endif
</div>