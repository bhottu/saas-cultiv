{{--
    Shared reporting period filter. Every advanced report accepts the same window
    so a figure can be reproduced by switching the range, never by editing SQL.

    $filters and $resetUrl are declared as props, otherwise Blade would push them
    into the HTML attribute bag and the <form> would render an array-valued attribute.
--}}
@props(['filters' => [], 'resetUrl' => null])
@php
    $selected = $filters['period'] ?? 'month';
    $presets = [
        'today'     => __('Today'),
        'yesterday' => __('Yesterday'),
        'week'      => __('This week'),
        'month'     => __('This month'),
        'quarter'   => __('This quarter'),
        'year'      => __('This year'),
    ];
@endphp

<form method="GET" {{ $attributes->merge(['class' => 'bg-white shadow rounded-lg p-5 mb-6']) }}>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 items-end">
        <div>
            <x-input-label for="period" :value="__('Period')" />
            <select id="period" name="period"
                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                    onchange="this.form.submit()">
                @foreach ($presets as $value => $label)
                    <option value="{{ $value }}" @selected($selected === $value)>{{ $label }}</option>
                @endforeach
                <option value="custom" @selected($selected === 'custom')>{{ __('Custom range') }}</option>
            </select>
        </div>

        <div>
            <x-input-label for="from" :value="__('From')" />
            <x-text-input id="from" name="from" type="date" class="mt-1 block w-full"
                          :value="$filters['from'] ?? ''" />
        </div>

        <div>
            <x-input-label for="to" :value="__('To')" />
            <x-text-input id="to" name="to" type="date" class="mt-1 block w-full"
                          :value="$filters['to'] ?? ''" />
        </div>

        <div class="flex gap-2">
            <x-primary-button>{{ __('Apply') }}</x-primary-button>
            @if ($resetUrl)
                <a href="{{ $resetUrl }}"
                   class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
                    {{ __('Reset') }}
                </a>
            @endif
        </div>
    </div>
</form>