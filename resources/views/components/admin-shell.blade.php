@props(['title', 'subtitle' => null, 'editable' => false])

{{--
    Shared admin page frame. Every /admin screen uses this so the sidebar, spacing
    and mobile behaviour are identical, and the Cultiv One design system is reused
    rather than reinvented.

    Most admin screens monitor tenant business records and are genuinely read-only, so
    they carry that badge by default. The screens that edit PLATFORM configuration
    instead of tenant data (plans, SEO) pass :editable="true", which replaces the badge
    with a hint that changes apply site-wide — the distinction that actually matters here
    is "am I editing my workspace or the whole platform", not "can I type in this form".
--}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ $title }}</h2>
                @if ($subtitle)
                    <p class="truncate text-sm text-gray-500">{{ $subtitle }}</p>
                @endif
            </div>
            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium {{ $editable ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-200 text-gray-700' }}">
                {{ $editable ? __('Applies site-wide') : __('Read only') }}
            </span>
        </div>
    </x-slot>

    <div class="mx-auto w-full max-w-screen-2xl px-0 py-6 sm:px-6 sm:py-8 lg:px-8">
        <div class="grid gap-8 lg:grid-cols-[18rem_minmax(0,1fr)]">
            <div class="min-w-0 lg:sticky lg:top-[5.5rem] lg:self-start">
                @include('layouts.admin-navigation')
            </div>

            <div class="min-w-0 space-y-8">
                @if (session('status'))
                    @php $status = session('status'); @endphp
                    <div role="alert"
                         class="rounded-lg p-3 text-sm {{ ($status['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                        {{ $status['message'] ?? '' }}
                    </div>
                @endif

                @if ($errors->any())
                    <div role="alert" class="rounded-lg bg-red-100 p-3 text-sm text-red-800">
                        <ul class="list-inside list-disc space-y-1">
                            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                {{ $slot }}
            </div>
        </div>
    </div>
</x-app-layout>
