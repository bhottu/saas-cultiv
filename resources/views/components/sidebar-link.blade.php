@props(['active' => false, 'icon' => null])

@php
    // The active marker reuses the already-established accent (border-indigo-400) so the
    // sidebar, the mobile drawer and the legacy top-nav share one visual language.
    $classes = ($active ?? false)
        ? 'group flex items-center gap-3 rounded-md border-l-4 border-indigo-400 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700 transition duration-150 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500'
        : 'group flex items-center gap-3 rounded-md border-l-4 border-transparent px-3 py-2 text-sm font-medium text-gray-600 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }} @if ($active) aria-current="page" @endif>
    @if ($icon)
        <x-nav-icon :name="$icon" />
    @endif

    <span class="truncate">{{ $slot }}</span>
</a>