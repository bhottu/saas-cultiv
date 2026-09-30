{{-- One KPI card; $hint may carry a period-over-period delta. --}}
@props(['label', 'value', 'hint' => null, 'tone' => 'default'])

@php
    $toneClass = match ($tone) {
        'positive' => 'text-emerald-700',
        'negative' => 'text-red-700',
        default    => 'text-gray-900',
    };
@endphp

<div class="rounded-lg bg-white p-5 shadow">
    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $label }}</div>
    <div class="mt-1 text-lg font-bold {{ $toneClass }}">{{ $value }}</div>
    @if ($hint)
        <div class="mt-1 text-xs text-gray-500">{{ $hint }}</div>
    @endif
</div>