{{-- One KPI card; $hint may carry a period-over-period delta. --}}
@props(['label', 'value', 'hint' => null, 'tone' => 'default', 'variant' => 'default'])

@php
    $adminStyle = $variant === 'admin';
    $toneClass = match ($tone) {
        'positive' => 'text-emerald-700',
        'negative' => 'text-red-700',
        default    => 'text-gray-900',
    };
@endphp

<div class="{{ $adminStyle ? 'admin-card flex min-h-28 flex-col p-5 sm:p-6' : 'rounded-lg bg-white p-5 shadow' }}">
    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $label }}</div>
    <div class="{{ $adminStyle ? 'mt-2 min-w-0 break-words text-2xl font-semibold leading-tight' : 'mt-1 text-lg font-bold' }} {{ $toneClass }}">{{ $value }}</div>
    @if ($hint)
        <div class="{{ $adminStyle ? 'mt-2 text-sm' : 'mt-1 text-xs' }} text-gray-500">{{ $hint }}</div>
    @endif
</div>