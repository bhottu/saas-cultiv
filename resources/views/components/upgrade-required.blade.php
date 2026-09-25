@props(['class' => ''])

@if (session('upgrade_required'))
    @php $upgrade = session('upgrade_required'); @endphp
    <section {{ $attributes->merge(['class' => 'mx-auto mb-6 w-full max-w-7xl rounded-xl border border-amber-300 bg-amber-50 p-4 sm:p-5 sm:px-6 lg:px-8']) }}
             aria-labelledby="upgrade-required-title" role="alert">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div>
                <h2 id="upgrade-required-title" class="font-semibold text-amber-950">Upgrade required</h2>
                <p class="mt-1 whitespace-pre-line text-sm leading-6 text-amber-900">{{ $upgrade['message'] ?? 'Upgrade your plan to continue.' }}</p>
            </div>
            <a href="{{ route('billing.index') }}"
               class="inline-flex shrink-0 items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                {{ __('View Plans') }}
            </a>
        </div>
    </section>
@endif