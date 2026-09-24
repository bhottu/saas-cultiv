<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'SaaS') }} — Multi-tenant SaaS with QRIS payments</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-white text-gray-800">
        {{-- Navbar --}}
        <nav class="border-b border-gray-100">
            <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
                <a href="{{ route('home') }}" class="text-lg font-bold text-indigo-600">
                    {{ config('app.name', 'SaaS') }}
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
            <h1 class="text-4xl md:text-5xl font-extrabold tracking-tight">
                Run your business <span class="text-indigo-600">securely</span> in one workspace
            </h1>
            <p class="mt-5 text-lg text-gray-500 max-w-2xl mx-auto">
                Multi-tenant SaaS with team roles, usage limits, and instant QRIS payments.
                Start free — upgrade whenever your team grows.
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
                    <div class="text-2xl mb-2">👥</div>
                    <h3 class="font-semibold">Teams & roles</h3>
                    <p class="text-sm text-gray-500 mt-1">Owner, Admin, Manager, Staff, Viewer — permissions enforced server-side.</p>
                </div>
                <div>
                    <div class="text-2xl mb-2">🔒</div>
                    <h3 class="font-semibold">Isolated workspaces</h3>
                    <p class="text-sm text-gray-500 mt-1">Every record is scoped to your tenant. Cross-workspace access is impossible.</p>
                </div>
                <div>
                    <div class="text-2xl mb-2">📱</div>
                    <h3 class="font-semibold">QRIS payments</h3>
                    <p class="text-sm text-gray-500 mt-1">Scan, pay, done. Subscription activates automatically once payment is verified.</p>
                </div>
            </div>
        </section>

        <footer class="max-w-6xl mx-auto px-4 py-10 text-center text-sm text-gray-400">
            {{ config('app.name', 'SaaS') }} — built on Laravel {{ app()->version() }}
        </footer>
    </body>
</html>

