<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- One H1 on the page: the hero headline. Every other section is an H2, so the
             outline stays a real document outline rather than headings used for styling. --}}
        {{-- The homepage. `home` opts into config('seo.default_title') rather than the
             "[page] — Cultiv" pattern (which would read "Cultiv — ... — Cultiv"), and
             into the organisation / website / page / application JSON-LD. It is also
             the only route listed in config('seo.indexable_routes'); every other page
             on the site inherits noindex by omission. --}}
        <x-seo home />
        <meta name="theme-color" content="{{ \App\Models\SeoSetting::config('theme_color') }}">

        <x-favicon />
        {{-- Apple pins the home-screen icon from this tag on iOS; without it Safari
             falls back to a screenshot of the page. Reuses the existing PWA artwork
             rather than introducing a second set of icons to keep in step. --}}
        <link rel="apple-touch-icon" href="{{ asset('pwa-192.png') }}">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="font-sans antialiased bg-white text-gray-800">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:shadow">
            {{ __('Skip to content') }}
        </a>

        {{-- ---------------------------------------------------------------- Navbar --}}
        {{-- The bar and the mobile panel share ONE Alpine scope: the button toggles `open`
             and the panel reads that same value. Two separate x-data roots would each keep
             their own copy of it and the menu would never actually open. Escape closes it
             from anywhere on the page. --}}
        <header class="sticky top-0 z-40 border-b border-gray-100 bg-white/90 backdrop-blur"
                x-data="{ open: false }"
                x-on:keydown.escape.window="open = false">
            <nav class="mx-auto max-w-6xl px-4" aria-label="{{ __('Main') }}">
                <div class="flex h-16 items-center justify-between gap-3">
                    <a href="{{ route('home') }}" class="min-w-0 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                        <x-brand-lockup :show-tagline="false" />
                    </a>

                    {{-- Desktop links. Hidden below lg, where the panel takes over. --}}
                    <div class="hidden items-center gap-7 text-sm font-medium text-gray-600 lg:flex">
                        <a href="#features" class="transition hover:text-gray-900">{{ __('Features') }}</a>
                        <a href="#modules" class="transition hover:text-gray-900">{{ __('Modules') }}</a>
                        <a href="#pricing" class="transition hover:text-gray-900">{{ __('Pricing') }}</a>
                        <a href="#faq" class="transition hover:text-gray-900">{{ __('FAQ') }}</a>
                    </div>

                    <div class="flex items-center gap-2">
                        @auth
                            <a href="{{ route('dashboard') }}" class="hidden rounded-lg px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 sm:inline-flex">
                                {{ __('Dashboard') }}
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden rounded-lg px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 sm:inline-flex">
                                {{ __('Log in') }}
                            </a>
                        @endauth

                        <a href="{{ route('register') }}"
                           class="inline-flex items-center rounded-lg bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            {{ __('Start free') }}
                        </a>

                        {{-- Mobile disclosure. Toggles the shared `open` state owned by the
                             header, which the panel below reads. --}}
                        <button type="button"
                                class="inline-flex h-10 w-10 items-center justify-center rounded-lg text-gray-700 transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 lg:hidden"
                                x-on:click="open = !open"
                                x-bind:aria-expanded="open"
                                aria-controls="mobile-nav"
                                aria-label="{{ __('Open menu') }}">
                            <x-nav-icon name="menu" x-show="!open" x-cloak />
                            <x-nav-icon name="close" x-show="open" x-cloak />
                        </button>
                    </div>
                </div>
            </nav>

            {{-- Mobile panel. It lives inside the header so it inherits the shared scope,
                 and below the fixed h-16 bar so the navbar itself never grows. Tapping any
                 link closes it through the click handler. --}}
            <div id="mobile-nav" x-show="open" x-cloak
                 x-on:click="open = false"
                 class="border-b border-gray-100 bg-white lg:hidden">
                <nav class="mx-auto max-w-6xl space-y-1 px-4 py-3 text-sm font-medium text-gray-700" aria-label="{{ __('Mobile') }}">
                    <a href="#features" class="block rounded-lg px-2 py-2.5 hover:bg-gray-50">{{ __('Features') }}</a>
                    <a href="#modules" class="block rounded-lg px-2 py-2.5 hover:bg-gray-50">{{ __('Modules') }}</a>
                    <a href="#pricing" class="block rounded-lg px-2 py-2.5 hover:bg-gray-50">{{ __('Pricing') }}</a>
                    <a href="#faq" class="block rounded-lg px-2 py-2.5 hover:bg-gray-50">{{ __('FAQ') }}</a>
                    @guest
                        <a href="{{ route('login') }}" class="block rounded-lg px-2 py-2.5 hover:bg-gray-50">{{ __('Log in') }}</a>
                    @endguest
                </nav>
            </div>
        </header>

        <main id="main">
            {{-- ---------------------------------------------------------------- Hero --}}
            {{-- The strongest visual weight on the page: generous space, the only large
                 type, and the primary CTA. --}}
            <section class="mx-auto max-w-6xl px-4 pb-16 pt-16 sm:pt-24">
                <div class="mx-auto max-w-3xl text-center">
                    <p class="mx-auto inline-flex items-center rounded-full border border-indigo-100 bg-indigo-50 px-3.5 py-1 text-xs font-semibold text-indigo-700">
                        {{ __('Business management, in one workspace') }}
                    </p>

                    <h1 class="mt-6 text-4xl font-extrabold tracking-tight text-gray-950 sm:text-5xl lg:text-6xl lg:leading-[1.1]">
                        {{ __('Run your business') }}
                        <span class="text-indigo-600">{{ __('smarter') }}</span>
                        {{ __('in one workspace') }}
                    </h1>

                    <p class="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-gray-600">
                        {{ __('Sales, inventory, customers, purchasing, payments and reports — all connected in one simple workspace.') }}
                    </p>

                    <div class="mt-9 flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:items-center">
                        <a href="{{ route('register') }}"
                           class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            {{ __('Start free') }}
                        </a>
                        <a href="#how-it-works"
                           class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            {{ __('See how it works') }}
                        </a>
                    </div>

                    <p class="mt-4 text-sm text-gray-500">{{ __('No credit card required.') }}</p>
                </div>

                @include('welcome.partials.workspace-map')
            </section>

{{-- ------------------------------------------------ One workspace (value prop) --}}
            <section class="border-y border-gray-100 bg-gray-50/50 py-20 sm:py-24">
                <div class="mx-auto max-w-6xl px-4">
                    <div class="mx-auto max-w-2xl text-center">
                        <h2 class="text-3xl font-bold tracking-tight text-gray-950 sm:text-4xl">
                            {{ __('One workspace. One source of truth.') }}
                        </h2>
                        <p class="mt-4 text-lg text-gray-600">
                            {{ __("Products, inventory, purchasing, sales, customers and reports work together, so you don't have to manage your business across disconnected tools.") }}
                        </p>
                    </div>

                    {{-- The flow, wrapped so it scrolls rather than overflowing on a phone. --}}
                    <div class="mt-12 -mx-4 overflow-x-auto px-4 pb-2">
                        <ol class="mx-auto flex min-w-max items-stretch gap-2 sm:min-w-0 sm:flex-wrap sm:justify-center">
                            @foreach ([
                                ['tag', __('Products')],
                                ['warehouse', __('Inventory')],
                                ['folder', __('Purchasing')],
                                ['shopping-cart', __('Sales')],
                                ['users', __('Customers')],
                                ['credit-card', __('Payments')],
                                ['chart-bar', __('Reports & Analytics')],
                            ] as [$icon, $label])
                                <li class="flex items-center gap-2">
                                    <span class="flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 shadow-sm">
                                        <x-nav-icon :name="$icon" class="h-4 w-4 text-indigo-600" />
                                        <span class="whitespace-nowrap text-xs font-semibold text-gray-800 sm:text-sm">{{ $label }}</span>
                                    </span>

                                    @if (! $loop->last)
                                        <x-nav-icon name="chevron-right" class="h-4 w-4 shrink-0 text-gray-300" />
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </div>

                    <p class="mx-auto mt-6 max-w-xl text-center text-sm text-gray-500">
                        {{ __('Record a sale once, and the rest of your business stays in step.') }}
                    </p>
                </div>
            </section>
{{-- ---------------------------------------------------- Problem → Solution --}}
            <section class="py-20 sm:py-24">
                <div class="mx-auto max-w-6xl px-4">
                    <h2 class="mx-auto max-w-2xl text-center text-3xl font-bold tracking-tight text-gray-950 sm:text-4xl">
                        {{ __('Stop managing your business across disconnected tools.') }}
                    </h2>

                    <div class="mt-14 grid gap-6 lg:grid-cols-2 lg:items-stretch">
                        {{-- Before: deliberately plain and grey. No fear-based copy. --}}
                        <div class="rounded-2xl border border-gray-200 bg-gray-50/60 p-6 sm:p-8">
                            <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-500">{{ __('Before') }}</h3>

                            <div class="mt-6 grid grid-cols-2 gap-3">
                                @foreach ([__('Products'), __('Sales'), __('Inventory'), __('Customers'), __('Reports'), __('Payments')] as $tool)
                                    <div class="rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-center text-xs font-medium text-gray-500">
                                        {{ $tool }}
                                    </div>
                                @endforeach
                            </div>

                            <p class="mt-6 border-l-2 border-gray-300 pl-4 text-sm leading-relaxed text-gray-500">
                                {{ __('Your products live in one place. Sales in another. Inventory somewhere else. Reports require manual work.') }}
                            </p>
                        </div>

                        {{-- After: one connected workspace. --}}
                        <div class="rounded-2xl border border-indigo-200 bg-white p-6 shadow-lg shadow-indigo-900/5 sm:p-8">
                            <h3 class="text-sm font-semibold uppercase tracking-wider text-indigo-600">{{ __('After') }}</h3>

                            <div class="mt-6 flex items-center gap-2.5 rounded-xl bg-indigo-600 px-4 py-3 text-white">
                                <x-nav-icon name="cube" class="h-5 w-5" />
                                <span class="text-sm font-bold tracking-wide">{{ __('CULTIV ONE') }}</span>
                            </div>

                            <ul class="mt-5 grid grid-cols-2 gap-x-4 gap-y-2.5 text-sm text-gray-700">
                                @foreach ([
                                    __('Products'), __('Inventory'), __('Purchasing'), __('Sales'),
                                    __('Customers'), __('Payments'), __('Reports'), __('Audit Log'),
                                ] as $capability)
                                    <li class="flex items-center gap-2">
                                        <x-nav-icon name="chevron-right" class="h-3.5 w-3.5 shrink-0 text-indigo-500" />
                                        <span class="min-w-0 truncate">{{ $capability }}</span>
                                    </li>
                                @endforeach
                            </ul>

                            <p class="mt-6 border-l-2 border-indigo-400 pl-4 text-sm leading-relaxed text-gray-700">
                                <span class="font-semibold">{{ __('Cultiv One brings your essential business operations together in one connected workspace.') }}</span>
                            </p>
                        </div>
                    </div>
                </div>
            </section>
{{-- --------------------------------------------------------- Core features --}}
            <section id="features" class="scroll-mt-20 border-y border-gray-100 bg-gray-50/50 py-20 sm:py-24">
                <div class="mx-auto max-w-6xl px-4">
                    <div class="mx-auto max-w-2xl text-center">
                        <h2 class="text-3xl font-bold tracking-tight text-gray-950 sm:text-4xl">
                            {{ __('Everything you need to run your business') }}
                        </h2>
                        <p class="mt-4 text-lg text-gray-600">{{ __('Essential business tools, connected from day one.') }}</p>
                    </div>

                    <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ([
                            ['cube', __('Products & Inventory'), __('Keep products, stock, warehouses and every stock movement in sync.')],
                            ['shopping-cart', __('Sales & Customers'), __('Manage sales, customers, payments and returns from one connected workflow.')],
                            ['folder', __('Purchasing'), __('Keep purchasing connected with your products and inventory.')],
                            ['chart-bar', __('Reports & Analytics'), __('Turn your business activity into clear information you can act on.')],
                            ['shield-check', __('Team & Permissions'), __('Give every team member the right access to the right business data.')],
                            ['bookmark', __('Audit & Control'), __('Keep important business activity traceable and accountable.')],
                        ] as [$icon, $title, $body])
                            <div class="group rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-md">
                                <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 transition group-hover:bg-indigo-600 group-hover:text-white">
                                    <x-nav-icon :name="$icon" class="h-5 w-5" />
                                </span>
                                <h3 class="mt-4 text-base font-semibold text-gray-900">{{ $title }}</h3>
                                <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ $body }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- ---------------------------------------------- Built for growing teams --}}
            <section class="py-20 sm:py-24">
                <div class="mx-auto max-w-6xl px-4">
                    <div class="mx-auto max-w-2xl text-center">
                        <h2 class="text-3xl font-bold tracking-tight text-gray-950 sm:text-4xl">
                            {{ __('Built for the way modern businesses grow') }}
                        </h2>
                        <p class="mt-4 text-lg text-gray-600">
                            {{ __('Start with the essentials and expand your workspace as your business grows.') }}
                        </p>
                    </div>

                    <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ([
                            [__('Small Business'), __('Keep products, customers and daily operations organized without unnecessary complexity.')],
                            [__('Retail'), __('Manage products, inventory and daily sales from one connected system.')],
                            [__('Wholesale'), __('Keep purchasing, inventory and customer activity connected.')],
                            [__('Growing Teams'), __('Add users, permissions and workspaces as your business expands.')],
                        ] as [$title, $body])
                            <div class="rounded-2xl border border-gray-200 bg-white p-6 transition hover:border-indigo-200 hover:shadow-md">
                                <h3 class="text-sm font-bold text-gray-900">{{ $title }}</h3>
                                <p class="mt-2.5 text-sm leading-relaxed text-gray-600">{{ $body }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
{{-- -------------------------------------------------------------- Modules --}}
            {{-- Data-driven from the same `modules` catalogue the app itself uses, so a
                 module added there appears here automatically. Nothing is hard-coded and
                 nothing is invented: POS is listed because POS exists. --}}
            @php
                // Data-driven from the same `modules` catalogue the app itself uses, so a
                // module added there appears here automatically. Nothing is hard-coded and
                // nothing is invented: POS is listed because POS exists.
                //
                // Written as a block, never the inline one-liner form: Blade pairs an
                // inline opening directive with a LATER closing directive, which silently
                // swallows everything in between as one raw block.
                $landingModules = \App\Models\Module::query()->available()->ordered()->get();
            @endphp
            <section id="modules" class="scroll-mt-20 border-y border-gray-100 bg-gray-50/50 py-20 sm:py-24">
                <div class="mx-auto max-w-6xl px-4">
                    <div class="mx-auto max-w-2xl text-center">
                        <h2 class="text-3xl font-bold tracking-tight text-gray-950 sm:text-4xl">
                            {{ __('Extend Cultiv One when your business needs more') }}
                        </h2>
                        <p class="mt-4 text-lg text-gray-600">
                            {{ __('Start with the essentials. Add powerful modules when you are ready.') }}
                        </p>
                    </div>

                    @if ($landingModules->isNotEmpty())
                        <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($landingModules as $module)
                                <div class="flex flex-col rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition hover:border-indigo-200 hover:shadow-md">
                                    <div class="flex items-start justify-between gap-3">
                                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                                            <x-nav-icon :name="$module->icon ?: 'cube'" class="h-5 w-5" />
                                        </span>
                                        <span class="shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700">
                                            {{ __('Available') }}
                                        </span>
                                    </div>

                                    <h3 class="mt-4 text-base font-semibold text-gray-900">{{ $module->name }}</h3>
                                    <p class="mt-2 flex-1 text-sm leading-relaxed text-gray-600">
                                        {{ $module->description ?: __('An optional capability for your workspace.') }}
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <p class="mt-8 text-center text-sm text-gray-500">{{ __('More modules coming soon.') }}</p>

                    <div class="mt-8 flex justify-center">
                        {{-- modules.index lives behind auth + tenant. For a signed-out visitor
                             that would bounce to the login screen, so guests are sent to the
                             pricing/step CTA instead. Never a dead link either way. --}}
                        @auth
                            <a href="{{ route('modules.index') }}"
                               class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                                {{ __('Explore modules') }}
                            </a>
                        @else
                            <a href="{{ route('register') }}"
                               class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                                {{ __('Start free to install modules') }}
                            </a>
                        @endauth
                    </div>
                </div>
            </section>

            {{-- --------------------------------------------------------- How it works --}}
            <section id="how-it-works" class="scroll-mt-20 py-20 sm:py-24">
                <div class="mx-auto max-w-6xl px-4">
                    <h2 class="mx-auto max-w-2xl text-center text-3xl font-bold tracking-tight text-gray-950 sm:text-4xl">
                        {{ __('Get started in minutes') }}
                    </h2>

                    <ol class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ([
                            [__('Create your workspace'), __('Set up your business in a few simple steps.')],
                            [__('Add your products'), __('Import or create your products and inventory.')],
                            [__('Start selling'), __('Manage sales, customers and purchases from one connected workflow.')],
                            [__('Understand your business'), __('Use reports and analytics to understand your business activity.')],
                        ] as $index => [$title, $body])
                            <li class="relative rounded-2xl border border-gray-200 bg-white p-6 transition hover:border-indigo-200 hover:shadow-md">
                                <span class="text-sm font-bold tabular-nums text-indigo-600">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                <h3 class="mt-3 text-base font-semibold text-gray-900">{{ $title }}</h3>
                                <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ $body }}</p>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>
{{-- -------------------------------------------------------------- Pricing --}}
            {{--
                Progressive pricing.

                The authoritative list still comes from <x-plan-capabilities>, the same
                component /billing and /admin/plans use — so this page can never advertise
                something a plan does not actually grant.

                The "what is new here" block is COMPUTED from each plan's own entitlements
                against the plan below it, not typed out by hand: a limit that grows and a
                capability that switches on both surface automatically, so this section
                cannot drift away from what the application enforces.
            --}}
            @php
                $capabilityLabels = [
                    'basic_sales' => 'Sales Management',
                    'basic_stock' => 'Inventory Management',
                    'basic_purchases' => 'Purchase Management',
                    'basic_reports' => 'Standard Reports',
                    'advanced_reports' => 'Advanced Reports',
                    'advanced_permissions' => 'Advanced Permissions',
                    'api_access' => 'API',
                    'audit_log' => 'Audit Log',
                    'advanced_analytics' => 'Advanced Analytics',
                ];

                $describe = static function ($value, string $noun): string {
                    if ($value === null) {
                        return __('Unlimited').' '.$noun;
                    }

                    return $value === '1' ? '1 '.$noun : $value.' '.$noun.'s';
                };

                // What this plan gains over the one directly below it in sort order.
                $gains = static function ($plan, $previous) use ($capabilityLabels, $describe): array {
                    if (! $previous) {
                        return [];
                    }

                    $gained = [];

                    foreach (['max_workspaces' => __('Workspaces'), 'max_users' => __('Users')] as $entitlement => $noun) {
                        $now = $plan->limit($entitlement);
                        $before = $previous->limit($entitlement);

                        if ($now !== $before && $now !== null) {
                            $gained[] = $describe($now, $noun);
                        }
                    }

                    foreach ($capabilityLabels as $key => $label) {
                        if ($plan->allows($key) && ! $previous->allows($key)) {
                            $gained[] = $label;
                        }
                    }

                    return $gained;
                };

                // Sort once so "the plan below" is a simple index lookup.
                $orderedPlans = $plans->sortBy('sort_order')->values();
            @endphp
<section id="pricing" class="scroll-mt-20 py-20 sm:py-24">
                <div class="mx-auto max-w-6xl px-4">
                    <div class="mx-auto max-w-2xl text-center">
                        <h2 class="text-3xl font-bold tracking-tight text-gray-950 sm:text-4xl">
                            {{ __('Simple pricing that grows with you') }}
                        </h2>
                        <p class="mt-4 text-lg text-gray-600">
                            {{ __('Every plan includes the core business tools. Add modules when you need them.') }}
                        </p>
                    </div>

                    <div class="mt-14 grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach ($orderedPlans as $index => $plan)
                            @php
                                // The plan directly below this one.
                                $previousPlan = $index > 0 ? $orderedPlans[$index - 1] : null;
                                $planGains = $gains($plan, $previousPlan);
                                $isFree = $plan->price_monthly <= 0 && $plan->price_yearly <= 0;
                                $featured = $plan->slug === 'pro';
                            @endphp

                            <div class="flex flex-col rounded-2xl border bg-white p-6 transition hover:shadow-md {{ $featured ? 'border-indigo-300 shadow-lg shadow-indigo-900/5 ring-1 ring-indigo-100' : 'border-gray-200 shadow-sm' }}">
                                @if ($featured)
                                    <span class="mb-3 inline-flex w-fit rounded-full bg-indigo-600 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wider text-white">
                                        {{ __('Most popular') }}
                                    </span>
                                @endif

                                <h3 class="text-lg font-bold text-gray-950">{{ $plan->name }}</h3>
                                <p class="mt-1.5 text-sm leading-relaxed text-gray-600">{{ $plan->description }}</p>

                                <div class="mt-5 flex items-baseline gap-1">
                                    @if ($isFree)
                                        <span class="text-3xl font-extrabold tracking-tight text-gray-950">{{ __('Free') }}</span>
                                    @else
                                        <span class="text-3xl font-extrabold tracking-tight text-gray-950">
                                            {{ \App\Services\Money::formatRupiah($plan->price_monthly) }}
                                        </span>
                                        <span class="text-sm text-gray-500">/{{ __('month') }}</span>
                                    @endif
                                </div>

                                @if ($planGains !== [])
                                    <div class="mt-5 rounded-xl bg-indigo-50/70 p-3.5">
                                        <p class="text-[11px] font-semibold tracking-wider text-indigo-700">
                                            {{ __('Everything in').' '.$previousPlan->name.', plus:' }}
                                        </p>
                                        <ul class="mt-2 space-y-1.5">
                                            @foreach ($planGains as $gain)
                                                <li class="flex items-start gap-2 text-sm font-medium text-gray-800">
                                                    <x-nav-icon name="chevron-right" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-indigo-500" />
                                                    <span class="min-w-0">{{ $gain }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                <div class="mt-6 border-t border-gray-100 pt-5">
                                    <x-plan-capabilities :plan="$plan" />
                                </div>

                                <a href="{{ route('register') }}"
                                   class="mt-6 inline-flex w-full items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 {{ $featured ? 'bg-indigo-600 text-white shadow-sm hover:bg-indigo-700' : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                                    {{ $isFree ? __('Start free') : __('Choose').' '.$plan->name }}
                                </a>
                            </div>
                        @endforeach
                    </div>

                    <p class="mt-10 flex flex-wrap items-center justify-center gap-2 text-center text-sm text-gray-600">
                        <x-nav-icon name="credit-card" class="h-4 w-4 text-gray-400" />
                        <span>{{ __('Payments via QRIS — scan with any Indonesian e-wallet or mobile banking app.') }}</span>
                    </p>
                </div>
            </section>
{{-- ------------------------------------------------------------------- FAQ --}}
            <section id="faq" class="scroll-mt-20 border-y border-gray-100 bg-gray-50/50 py-20 sm:py-24">
                <div class="mx-auto max-w-3xl px-4">
                    <h2 class="text-center text-3xl font-bold tracking-tight text-gray-950 sm:text-4xl">
                        {{ __('Frequently asked questions') }}
                    </h2>

                    {{-- Native <details>/<summary>: a real accordion with keyboard support,
                         no JS, and it still answers the question when scripting is off. --}}
                    <div class="mt-12 divide-y divide-gray-200 overflow-hidden rounded-2xl border border-gray-200 bg-white">
                        @foreach ([
                            [__('Is Cultiv One free?'), __('Yes. The Free plan provides core business tools so you can start managing your business without a subscription.')],
                            [__('Can I upgrade later?'), __('Yes. You can upgrade your plan as your business needs grow.')],
                            [__('What is a Workspace?'), __('A Workspace is the environment where your business products, sales, customers, inventory and other business data are managed.')],
                            [__('What are Modules?'), __('Modules are optional capabilities that extend Cultiv One. You can add them when your business needs them.')],
                            [__('Is Point of Sale included?'), __('Point of Sale is available as a module and can be enabled separately from the core Cultiv One experience.')],
                            [__('Do I need a credit card to start?'), __('No. You can start with the Free plan.')],
                            [__('Can I use QRIS?'), __('Yes. Cultiv One supports QRIS payments for supported transactions.')],
                        ] as $index => [$question, $answer])
                            <details class="group" @if ($index === 0) open @endif>
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 text-left text-sm font-semibold text-gray-900 transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500 sm:text-base">
                                    <span class="min-w-0">{{ $question }}</span>
                                    <x-nav-icon name="chevron-down" class="h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-180" />
                                </summary>
                                <div class="px-5 pb-5 text-sm leading-relaxed text-gray-600">
                                    {{ $answer }}
                                </div>
                            </details>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- ------------------------------------------------------------ Final CTA --}}
            <section class="py-20 sm:py-24">
                <div class="mx-auto max-w-6xl px-4">
                    <div class="rounded-3xl bg-gray-950 px-6 py-14 text-center sm:px-12 sm:py-16">
                        <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                            {{ __('Ready to run your business smarter?') }}
                        </h2>
                        <p class="mx-auto mt-4 max-w-xl text-lg text-gray-300">
                            {{ __('Start with the tools you need today. Grow into more powerful capabilities when you are ready.') }}
                        </p>

                        <div class="mt-9 flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:items-center">
                            <a href="{{ route('register') }}"
                               class="inline-flex items-center justify-center rounded-lg bg-white px-6 py-3 text-sm font-semibold text-gray-900 shadow-sm transition hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-gray-950">
                                {{ __('Start free') }}
                            </a>
                            <a href="#pricing"
                               class="inline-flex items-center justify-center rounded-lg border border-gray-700 px-6 py-3 text-sm font-semibold text-gray-200 transition hover:bg-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-gray-950">
                                {{ __('See pricing') }}
                            </a>
                        </div>

                        <p class="mt-4 text-sm text-gray-400">{{ __('No credit card required.') }}</p>
                    </div>
                </div>
            </section>
        </main>
{{-- ---------------------------------------------------------------- Footer --}}
        <footer class="border-t border-gray-100 bg-white">
            <div class="mx-auto max-w-6xl px-4 py-12">
                <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="lg:col-span-2">
                        <x-brand-lockup />
                        <p class="mt-4 max-w-xs text-sm leading-relaxed text-gray-500">
                            {{ config('app.tagline', 'The smarter way to manage your business.') }}
                        </p>
                    </div>

                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-900">{{ __('Product') }}</h3>
                        <ul class="mt-4 space-y-2.5 text-sm text-gray-600">
                            <li><a href="#features" class="transition hover:text-gray-900">{{ __('Features') }}</a></li>
                            <li><a href="#modules" class="transition hover:text-gray-900">{{ __('Modules') }}</a></li>
                            <li><a href="#pricing" class="transition hover:text-gray-900">{{ __('Pricing') }}</a></li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-900">{{ __('Account') }}</h3>
                        <ul class="mt-4 space-y-2.5 text-sm text-gray-600">
                            <li><a href="{{ route('login') }}" class="transition hover:text-gray-900">{{ __('Log in') }}</a></li>
                            <li><a href="{{ route('register') }}" class="transition hover:text-gray-900">{{ __('Register') }}</a></li>
                        </ul>
                    </div>
                </div>

                {{-- No Legal column: there are no privacy or terms routes in this
                     application, and linking to them would be a broken link. --}}

                <div class="mt-10 border-t border-gray-100 pt-6 text-sm text-gray-500">
                    {{ __('© :year :name. All rights reserved.', ['year' => date('Y'), 'name' => config('app.name', 'Cultiv One')]) }}
                </div>
            </div>
        </footer>
    {{-- Floating install entry point.

         ONE install source: this reuses the same pwaInstall component the application
         header uses, so there is no second beforeinstallprompt listener, no second
         install state and no second service worker anywhere.

         Visibility rules (all inside the shared component):
           • installed / standalone  → nothing renders
           • Chromium with a prompt → a real button that opens the native dialog
           • iOS                    → the same button, revealing Share → Add to Home Screen
           • anything else          → nothing renders, never a dead control

         Width is w-fit so it hugs its label instead of spanning the viewport, and the
         safe-area inset keeps it clear of the iOS home indicator. z-50 sits above the
         sticky header (z-40) without covering any navigation: it is anchored to the
         bottom of the viewport, where the footer already ends. --}}
    <div x-data="pwaInstall" x-init="init()" x-cloak x-show="!installed && (canInstall || showIosInstructions)"
         class="fixed inset-x-0 bottom-0 z-50 flex justify-center px-4 pb-4 pointer-events-none sm:justify-end sm:px-6 sm:pb-6">
        <div class="pointer-events-auto w-fit max-w-sm">
            <div x-show="iosHelpOpen" x-transition
                 role="status"
                 class="mb-2 w-fit max-w-xs rounded-lg bg-gray-900 px-3 py-2 text-start text-xs text-white shadow-lg">
                {{ __('Open the Share menu in the browser, then choose "Add to Home Screen" to install Cultiv.') }}
            </div>

            <button type="button" @click="activate()"
                    class="inline-flex items-center gap-2 rounded-full bg-gray-900 px-4 py-3 text-sm font-medium text-white shadow-lg hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                <x-nav-icon name="download" class="h-4 w-4" />
                {{ __('Install Cultiv') }}
            </button>
        </div>
    </div>
</body>
</html>