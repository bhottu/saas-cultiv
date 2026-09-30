@props(['showTagline' => true])

@php
    // Workspace branding wins when there is an active workspace. Without one (sign-in,
    // landing page, a fresh account) the product identity from config/app.php is used,
    // so those pages look exactly as they did before this feature existed.
    $tenant = app('tenant.context')->tenant();

    $name = $tenant
        ? $tenant->brandName()
        : (string) config('app.name', 'Cultiv One');

    $tagline = $tenant
        ? $tenant->brandTagline()
        : (string) config('app.tagline', 'The smarter way to manage your business');

    $logo = $tenant?->brandLogoUrl();
@endphp

<div {{ $attributes->merge(['class' => 'flex min-w-0 items-center gap-3']) }}>
    @if ($logo)
        {{-- object-contain + a neutral plate: a logo of any aspect ratio keeps its
             own shape instead of being cropped into a square. --}}
        <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white ring-1 ring-gray-200">
            <img src="{{ $logo }}" alt="{{ $name }}" class="h-full w-full object-contain" />
        </span>
    @else
        <x-application-logo class="h-10 w-10 shrink-0 text-indigo-600" />
    @endif

    <span class="min-w-0">
        <span class="sr-only">{{ $name }} — {{ $tagline }}</span>
        <span class="block truncate text-base font-bold leading-tight tracking-tight text-gray-950">
            {{ $name }}
        </span>
        @if ($showTagline)
            {{-- truncate, not wrap: a long custom description must never push the
                 sidebar menu down. The form caps the length as well. --}}
            <span class="mt-0.5 block truncate text-[10px] font-medium leading-4 text-gray-500">
                {{ $tagline }}
            </span>
        @endif
    </span>
</div>