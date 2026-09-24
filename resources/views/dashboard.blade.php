<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ $tenant->name }}</h2>
                <p class="truncate text-sm text-gray-500">{{ __('Business dashboard') }}</p>
            </div>

            <a href="{{ route('billing.index') }}"
               class="inline-flex shrink-0 items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                {{ __('Manage billing') }}
            </a>
        </div>
    </x-slot>

    @php
        // Read-only summary of the active tenant — the dashboard never becomes a CRUD screen.
        $seatUsed = $usage['max_users'] ?? 0;
        $seatLimit = $subscription?->plan?->limit('max_users');
        $apiUsed = $apiUsage['used'] ?? 0;
        $apiLimit = $apiUsage['limit'] ?? null;

        $percent = fn ($used, $limit) => $limit ? min(100, (int) round(((int) $used / max(1, (int) $limit)) * 100)) : null;
        $barColour = fn (?int $pct) => $pct !== null && $pct >= 90 ? 'bg-red-500' : 'bg-indigo-600';
    @endphp

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        @if (session('success'))
            <div class="rounded-lg bg-green-100 p-3 text-green-800">{{ session('success') }}</div>
        @endif

        {{-- Summary --}}
        <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
            {{-- Subscription --}}
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Subscription') }}</div>

                @if ($subscription)
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <span class="text-lg font-bold text-gray-900">{{ $subscription->plan->name }}</span>
                        <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs text-indigo-700">{{ ucfirst($subscription->status) }}</span>
                    </div>
                    <div class="mt-1 text-sm text-gray-500">
                        @if ($subscription->current_period_end)
                            {{ $subscription->status === 'trialing' ? __('Trial ends') : __('Renews') }}:
                            {{ $subscription->current_period_end->format('d M Y') }}
                        @else
                            {{ __('No renewal date') }}
                        @endif
                    </div>
                @else
                    <div class="mt-1 text-sm text-gray-500">{{ __('No active subscription') }}</div>
                    <a href="{{ route('billing.index') }}" class="mt-1 inline-block text-sm text-indigo-600 underline">{{ __('Choose a plan') }}</a>
                @endif
            </div>

            {{-- API usage --}}
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('API calls (this month)') }}</div>
                <div class="mt-1 text-lg font-bold text-gray-900">{{ $apiUsed }} / {{ $apiLimit ?? '∞' }}</div>
                @php $apiPercent = $percent($apiUsed, $apiLimit); @endphp
                @if ($apiPercent !== null)
                    <div class="mt-2 h-2 rounded bg-gray-200">
                        <div class="h-2 rounded {{ $barColour($apiPercent) }}" style="width: {{ $apiPercent }}%"></div>
                    </div>
                @else
                    <div class="mt-1 text-sm text-gray-500">{{ __('Unlimited on your plan') }}</div>
                @endif
            </div>

            {{-- Team seats --}}
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Team members') }}</div>
                <div class="mt-1 text-lg font-bold text-gray-900">{{ $seatUsed }} / {{ $seatLimit ?? '∞' }}</div>
                @php $seatPercent = $percent($seatUsed, $seatLimit); @endphp
                @if ($seatPercent !== null)
                    <div class="mt-2 h-2 rounded bg-gray-200">
                        <div class="h-2 rounded {{ $barColour($seatPercent) }}" style="width: {{ $seatPercent }}%"></div>
                    </div>
                @else
                    <div class="mt-1 text-sm text-gray-500">{{ __('Unlimited on your plan') }}</div>
                @endif
            </div>

            {{-- Invoices --}}
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Invoices') }}</div>
                <div class="mt-1 text-lg font-bold text-gray-900">{{ $recentInvoices->count() }}</div>
                @if ($recentInvoices->first())
                    <div class="mt-1 text-sm text-gray-500">
                        {{ __('Latest') }}: {{ ucfirst($recentInvoices->first()->status) }}
                    </div>
                @else
                    <div class="mt-1 text-sm text-gray-500">{{ __('No invoices yet.') }}</div>
                @endif
            </div>
        </div>

        {{-- Recent activity --}}
        <div class="grid gap-6 md:grid-cols-2">
            <div class="rounded-lg bg-white p-6 shadow">
                <h3 class="mb-3 font-semibold text-gray-900">{{ __('Recent payments') }}</h3>

                <ul class="divide-y divide-gray-100 text-sm">
                    @forelse ($recentPayments as $p)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="truncate font-mono text-xs text-gray-500">{{ $p->order_id }}</span>
                            <span class="shrink-0 text-gray-700">IDR {{ number_format($p->amount) }}</span>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs {{ ($p->status ?? '') === 'paid' ? 'bg-green-100 text-green-700' : (($p->status ?? '') === 'pending' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600') }}">
                                {{ ucfirst($p->status ?? 'unknown') }}
                            </span>
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">{{ __('No payments yet.') }}</li>
                    @endforelse
                </ul>
            </div>

            <div class="rounded-lg bg-white p-6 shadow">
                <h3 class="mb-3 font-semibold text-gray-900">{{ __('Notifications') }}</h3>

                <ul class="divide-y divide-gray-100 text-sm">
                    @forelse ($notifications as $n)
                        <li class="py-2 text-gray-700">
                            {{ $n->data['message'] ?? __('Notification') }}
                            <span class="block text-xs text-gray-400">{{ $n->created_at->diffForHumans() }}</span>
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">{{ __('Nothing new.') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>

        {{-- Business sales: same shared data and widgets as /sales/dashboard. --}}
        @if ($salesOverview)
            <section aria-labelledby="sales-overview-title">
                <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h3 id="sales-overview-title" class="text-lg font-semibold text-gray-900">{{ __('Sales performance') }}</h3>
                        <p class="text-sm text-gray-500">{{ __('Today, this month and the products that sell best.') }}</p>
                    </div>
                    <a href="{{ route('sales.index') }}" class="text-sm font-medium text-indigo-600 hover:underline">{{ __('View all orders') }}</a>
                </div>

                @include('sales.partials.dashboard-summary', ['dashboardData' => $salesOverview])
            </section>
        @endif
    </div>
</x-app-layout>