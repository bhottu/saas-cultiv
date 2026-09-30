@props(['class' => ''])

@if (session('upgrade_required'))
    @php
        $upgrade = session('upgrade_required');

        // The banner is rendered by the shell, ABOVE the page's own container, so it has
        // to reproduce that container's width or it spans the full content area while
        // the cards underneath sit in a narrower, centred column — which is what made
        // it look misaligned. Page containers are not uniform: listings and dashboards
        // run at 7xl, while narrow transactional screens cap at 4xl, so the widths are
        // mirrored here. Keep this list in step with the `max-w-*` on those views.
        $narrowPageRoutes = [
            'team.index', 'tokens.index', 'files.index',
            'brands.create', 'brands.edit',
            'categories.create', 'categories.edit',
            'products.create', 'products.edit',
            'expenses.show', 'audit-logs.show', 'modules.show',
            'sales.print', 'sales.return',
        ];

        $containerWidth = in_array(request()->route()?->getName(), $narrowPageRoutes, true)
            ? 'max-w-4xl'
            : 'max-w-7xl';
    @endphp
    {{-- Horizontal padding lives on this transparent wrapper, NOT on the coloured
         box. The page containers inset their own cards with `sm:px-6 lg:px-8`, so
         keeping the padding on the banner's border box made the amber rectangle
         32px wider on each side than every card below it. Moving it out here makes
         the coloured box line up with them exactly, and the inner padding stays at
         the same 16/20px so the text sits on the same optical edge as the cards.
         `pt-6` gives the banner the same 24px clearance from the header that the
         `gap-6` grids use. --}}
    <div class="mx-auto w-full {{ $containerWidth }} pt-6 sm:px-6 lg:px-8">
        <section {{ $attributes->merge(['class' => 'rounded-xl border border-amber-300 bg-amber-50 p-4 sm:p-5']) }}
                 aria-labelledby="upgrade-required-title" role="alert" data-testid="upgrade-required">
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
    </div>
@endif