{{--
    Hero product visual.

    A schematic, not a screenshot: Cultiv One has no product screenshots in the repo,
    and inventing one full of invented revenue figures would be dishonest. So this
    shows the real module names and the real shape of the app shell, with neutral
    placeholders instead of fabricated numbers, customers or statistics.

    Pure CSS/SVG: no image request, nothing to lazy-load, nothing to go missing.
--}}
<div class="mx-auto mt-14 max-w-5xl sm:mt-20">
    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-xl shadow-gray-900/5">
        {{-- Window chrome. --}}
        <div class="flex items-center gap-2 border-b border-gray-100 bg-gray-50/80 px-4 py-3">
            <span class="h-2.5 w-2.5 rounded-full bg-gray-300"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-gray-300"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-gray-300"></span>
            <span class="ml-3 truncate rounded bg-white px-2.5 py-1 text-[11px] font-medium text-gray-400">
                {{ __('Cultiv One · My Workspace') }}
            </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-[13rem_1fr]">
            {{-- Sidebar: the real navigation sections. --}}
            <div class="hidden flex-col gap-0.5 border-b border-gray-100 p-3 sm:flex sm:border-b-0 sm:border-r">
                @foreach ([
                    ['chart-bar', __('Dashboard')],
                    ['tag', __('Sales')],
                    ['cube', __('Products')],
                    ['warehouse', __('Stock')],
                    ['users', __('Customers')],
                    ['folder', __('Purchasing')],
                    ['credit-card', __('Payments')],
                ] as [$icon, $label])
                    <span class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-xs font-medium text-gray-600">
                        <x-nav-icon :name="$icon" class="h-4 w-4 text-gray-400" />
                        <span class="truncate">{{ $label }}</span>
                    </span>
                @endforeach
            </div>

            {{-- Content: an order being recorded, drawn as neutral shapes. --}}
            <div class="p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm font-semibold text-gray-900">{{ __('New sale') }}</span>
                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700">
                        {{ __('Recorded') }}
                    </span>
                </div>

                {{-- Line items. Grey bars stand in for the fields; no invented money. --}}
                <div class="mt-5 space-y-3">
                    @foreach ([1, 2, 3] as $row)
                        <div class="flex items-center gap-3">
                            <span class="h-7 w-7 shrink-0 rounded-md bg-gray-100"></span>
                            <span class="h-2 flex-1 rounded bg-gray-100" style="width: {{ [70, 55, 62][$row - 1] }}%"></span>
                            <span class="h-2 w-10 shrink-0 rounded bg-gray-100"></span>
                        </div>
                    @endforeach
                </div>

                {{-- Connected effects: what the sale just touched. --}}
                <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach ([
                        ['warehouse', __('Stock updated')],
                        ['users', __('Customer recorded')],
                        ['chart-bar', __('Reports updated')],
                    ] as [$icon, $label])
                        <div class="flex items-center gap-2 rounded-lg border border-gray-100 bg-gray-50/60 px-3 py-2.5">
                            <x-nav-icon :name="$icon" class="h-4 w-4 text-indigo-500" />
                            <span class="text-[11px] font-medium leading-tight text-gray-600">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>