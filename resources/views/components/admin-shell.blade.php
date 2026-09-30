@props(['title', 'subtitle' => null])

{{--
    Shared admin page frame. Every /admin screen uses this so the sidebar, spacing
    and mobile behaviour are identical, and the Cultiv One design system is reused
    rather than reinvented. Read-only note is deliberate: admins monitor, they do
    not edit tenant business records from here.
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
            <span class="shrink-0 rounded-full bg-gray-200 px-2.5 py-1 text-xs font-medium text-gray-700">
                {{ __('Read only') }}
            </span>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="grid gap-6 lg:grid-cols-[18rem_minmax(0,1fr)]">
            <div class="lg:sticky lg:top-24 lg:self-start">
                @include('layouts.admin-navigation')
            </div>

            <div class="min-w-0 space-y-6">
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
