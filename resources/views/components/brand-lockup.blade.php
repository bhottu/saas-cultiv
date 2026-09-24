@props(['showTagline' => true])

<div {{ $attributes->merge(['class' => 'flex min-w-0 items-center gap-3']) }}>
    <x-application-logo class="h-10 w-10 shrink-0 text-indigo-600" />

    <span class="min-w-0">
        <span class="sr-only">{{ config('app.name', 'Cultiv One') }} — {{ config('app.tagline', 'The smarter way to manage your business') }}</span>
        <span class="block truncate text-base font-bold leading-tight tracking-tight text-gray-950">
            {{ config('app.name', 'Cultiv One') }}
        </span>
        @if ($showTagline)
            <span class="mt-0.5 block text-[10px] font-medium leading-4 text-gray-500">
                {{ config('app.tagline', 'The smarter way to manage your business') }}
            </span>
        @endif
    </span>
</div>