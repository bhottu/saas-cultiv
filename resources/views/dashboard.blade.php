<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $tenant->name }} — Dashboard
        </h2>
    </x-slot>

    <div class="py-12 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white rounded-lg shadow p-6">
            @if ($subscription)
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="text-sm text-gray-500">Current plan</div>
                        <div class="text-2xl font-bold">
                            {{ $subscription->plan->name }}
                            <span class="text-sm font-normal px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700">{{ ucfirst($subscription->status) }}</span>
                        </div>
                        @if ($subscription->current_period_end)
                            <div class="text-sm text-gray-500 mt-1">
                                {{ $subscription->status === 'trialing' ? 'Trial ends' : 'Renews' }}:
                                {{ $subscription->current_period_end->format('d M Y') }}
                            </div>
                        @endif
                    </div>
                    <a href="{{ route('billing.index') }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Manage billing</a>
                </div>
            @else
                <p>No active subscription. <a class="text-indigo-600 underline" href="{{ route('billing.index') }}">Choose a plan →</a></p>
            @endif
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="font-semibold mb-3">Usage & quota</h3>
            <div class="space-y-3 text-sm">
                <div class="flex justify-between"><span>API calls (this month)</span><span>{{ $apiUsage['used'] }} / {{ $apiUsage['limit'] ?? '∞' }}</span></div>
                <div class="flex justify-between"><span>Team members</span><span>{{ $usage['max_users'] }} / {{ $subscription?->plan?->limit('max_users') ?? '∞' }}</span></div>
            </div>
        </div>

        <div class="grid md:grid-cols-2 gap-6">
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-semibold mb-3">Recent payments</h3>
                <ul class="text-sm divide-y">
                    @forelse ($recentPayments as $p)
                        <li class="py-2 flex justify-between">
                            <span class="font-mono text-xs">{{ $p->order_id }}</span>
                            <span>IDR {{ number_format($p->amount) }} — {{ ucfirst($p->status) }}</span>
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">No payments yet.</li>
                    @endforelse
                </ul>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-semibold mb-3">Notifications</h3>
                <ul class="text-sm divide-y">
                    @forelse ($notifications as $n)
                        <li class="py-2">{{ $n->data['message'] ?? 'Notification' }}
                            <span class="text-gray-400 text-xs block">{{ $n->created_at->diffForHumans() }}</span></li>
                    @empty
                        <li class="py-2 text-gray-500">Nothing new.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
