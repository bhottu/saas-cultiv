@props(['submit', 'reset' => null, 'columns' => 3])

{{--
    Filter bar for admin explorers. Always a GET form so it never triggers the
    global submit loading state (a filter is a read, not a write).
--}}
<form method="GET" action="{{ $submit }}" class="rounded-lg bg-white p-4 shadow">
    <div class="grid gap-3 md:grid-cols-{{ $columns }}"> {{ $slot }} </div>

    <div class="mt-4 flex flex-wrap items-center gap-2">
        <x-primary-button>{{ __('Filter') }}</x-primary-button>
        <a href="{{ $reset ?? $submit }}" class="text-sm text-gray-500 underline">{{ __('Reset') }}</a>
    </div>
</form>
