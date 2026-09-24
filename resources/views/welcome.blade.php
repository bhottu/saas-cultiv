<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Cultiv One') }} - {{ config('app.tagline', 'The smarter way to manage your business') }}</title>
        <meta name="description" content="Cultiv One is the smarter way to manage sales, inventory, customers, purchasing and business performance in one workspace.">
        <meta property="og:title" content="{{ config('app.name', 'Cultiv One') }} - {{ config('app.tagline', 'The smarter way to manage your business') }}">
        <meta property="og:description" content="A modern business management platform for sales, inventory, customers and insights.">
        <meta name="theme-color" content="#4f46e5">
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <link rel="alternate icon" type="image/png" sizes="64x64" href="{{ asset('favicon-64.png') }}">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-white text-gray-800">
        {{-- Navbar --}}
        <nav class="border-b border-gray-100">
            <div class="max-w-6xl mx-auto px-4 min-h-20 flex flex-wrap items-center justify-between gap-3 py-2">
                <a href="{{ route('home') }}" class="block min-w-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                    <x-brand-lockup />
                </a>
                <div class="flex items-center gap-4 text-sm">
                    @auth
                        <a href="{{ route('dashboard') }}" class="text-gray-600 hover:text-gray-900">Dashboard</a>
                        <a href="{{ route('billing.index') }}" class="text-gray-600 hover:text-gray-900">Billing</a>
                    @else
                        <a href="{{ route('login') }}" class="text-gray-600 hover:text-gray-900">Log in</a>
                        <a href="{{ route('register') }}" class="px-3 py-1.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Register</a>
                    @endauth
                </div>
            </div>
        </nav>

        {{-- Hero --}}
        <section class="max-w-6xl mx-auto px-4 pt-20 pb-14 text-center">
            <div class="mx-auto mb-6 inline-flex rounded-full border border-indigo-100 bg-indigo-50 px-4 py-1.5 text-sm font-semibold text-indigo-700">
                {{ config('app.tagline', 'The smarter way to manage your business') }}
            </div>
            <h1 class="text-4xl md:text-5xl font-extrabold tracking-tight text-gray-950">
                Run your business <span class="text-indigo-600">smarter</span> in one workspace
            </h1>
            <p class="mt-5 text-lg text-gray-500 max-w-2xl mx-auto">
                Sales, inventory, customers, purchasing, payments and reports—designed for any business that wants one reliable source of truth.
            </p>
            <div class="mt-8 flex justify-center gap-3">
                @auth
                    <a href="{{ route('dashboard') }}" class="px-6 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-semibold">Open dashboard</a>
                @else
                    <a href="{{ route('register') }}" class="px-6 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-semibold">Start free</a>
                    <a href="{{ route('login') }}" class="px-6 py-3 border border-gray-300 rounded-lg hover:bg-gray-50 font-semibold">Log in</a>
                @endauth
            </div>
        </section>

        {{-- Pricing --}}
        <section class="max-w-6xl mx-auto px-4 pb-20">
            <h2 class="text-2xl font-bold text-center mb-8">Simple pricing</h2>
            <div class="grid md:grid-cols-{{ min(4, max(2, $plans->count())) }} gap-5">
                @foreach ($plans as $plan)
                    <div class="border rounded-xl p-6 flex flex-col {{ $plan->slug === 'pro' ? 'border-indigo-500 ring-2 ring-indigo-200 shadow-lg' : 'border-gray-200' }}">
                        <div class="font-bold text-lg">{{ $plan->name }}</div>
                        <p class="text-sm text-gray-500 mt-1 mb-3">{{ $plan->description }}</p>
                        <div class="text-2xl font-extrabold mb-4">
                            @if ($plan->price_monthly > 0)
                                Rp {{ number_format($plan->price_monthly) }}
                                <span class="text-sm font-normal text-gray-500">/month</span>
                            @else
                                Free
                            @endif
                        </div>
                        <ul class="text-sm text-gray-600 space-y-1.5 mb-6 flex-1">
                            @foreach ($plan->features ?? [] as $feature)
                                <li class="flex gap-2"><span class="text-green-500">✓</span> {{ $feature }}</li>
                            @endforeach
                        </ul>
                        <a href="{{ auth()->check() ? route('billing.index') : route('register') }}"
                           class="text-center px-4 py-2 rounded-lg font-semibold text-sm
                                  {{ $plan->slug === 'pro' ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'border border-gray-300 hover:bg-gray-50' }}">
                            {{ $plan->price_monthly > 0 ? 'Choose ' . $plan->name : 'Start free' }}
                        </a>
                    </div>
                @endforeach
            </div>
            <p class="text-center text-sm text-gray-400 mt-6">Payments via QRIS — scan with any Indonesian e-wallet / mobile banking app.</p>
        </section>

        {{-- Features --}}
        <section class="bg-gray-50 border-y border-gray-100">
            <div class="max-w-6xl mx-auto px-4 py-16 grid md:grid-cols-3 gap-8 text-center">
                <div>
                    <div class="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-xl">📦</div>
                    <h3 class="font-semibold">Products & inventory</h3>
                    <p class="text-sm text-gray-500 mt-1">Keep products, stock, warehouses and every stock movement in sync.</p>
                </div>
                <div>
                    <div class="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-xl">🧾</div>
                    <h3 class="font-semibold">Sales & customers</h3>
                    <p class="text-sm text-gray-500 mt-1">Manage orders, payments, customers and returns from one connected workflow.</p>
                </div>
                <div>
                    <div class="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-xl">📈</div>
                    <h3 class="font-semibold">Reports & control</h3>
                    <p class="text-sm text-gray-500 mt-1">Understand performance with clear reports, secure roles and auditable activity.</p>
                </div>
            </div>
        </section>

        <footer class="border-t border-gray-100 bg-gray-50">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 py-8 text-center text-sm text-gray-500 sm:flex-row sm:text-left">
                <x-brand-lockup />
                <p>© {{ now()->year }} {{ config('app.name', 'Cultiv One') }}. All rights reserved.</p>
            </div>
        </footer>
    </body>
</html>

