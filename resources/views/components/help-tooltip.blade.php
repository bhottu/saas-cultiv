@props(['text', 'label' => null, 'id' => null])

@php
    // Small reusable "?" affordance used next to form labels (no JS dependency beyond Alpine,
    // which every page already loads through resources/js/app.js).
    $tooltipId = $id ?? 'help-'.\Illuminate\Support\Str::random(8);
@endphp

<span x-data="{ open: false }" class="relative inline-flex align-middle" @keydown.escape.window="open = false">
    <button type="button"
            @click="open = ! open"
            x-bind:aria-expanded="open"
            aria-describedby="{{ $tooltipId }}"
            aria-label="{{ $label ? __('About :field', ['field' => $label]) : __('More information') }}"
            class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-gray-400 text-[10px] font-semibold leading-none text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
        ?
    </button>

    <span x-show="open"
          style="display: none;"
          x-transition.opacity
          @click.outside="open = false"
          id="{{ $tooltipId }}"
          role="tooltip"
          class="absolute left-0 top-full z-30 mt-2 w-64 rounded-lg bg-gray-900 px-3 py-2 text-xs font-normal leading-relaxed text-white shadow-lg sm:left-1/2 sm:w-72 sm:-translate-x-1/2">
        {{ $text }}
    </span>
</span>