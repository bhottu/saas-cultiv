<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Billing') }}</h2>
    </x-slot>

    <div class="py-12 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if (session('success'))
            <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('success') }}</div>
        @endif

        {{-- Current subscription --}}
        <div class="bg-white rounded-lg shadow p-6">
            @if ($subscription)
                <div class="flex flex-wrap justify-between items-center">
                    <div>
                        <span class="text-sm text-gray-500">Current plan:</span>
                        <span class="font-bold text-lg">{{ $subscription->plan->name }}</span>
                        <span class="text-sm px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 ml-2">{{ ucfirst($subscription->status) }}</span>
                        @if ($subscription->current_period_end)
                            <span class="text-sm text-gray-500 ml-3">{{ ucfirst($subscription->status) === 'Trial' ? 'Trial ends' : 'Renews' }} {{ $subscription->current_period_end->format('d M Y') }}</span>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('billing.checkout') }}">
                        @csrf
                        <input type="hidden" name="plan" value="free">
                        <input type="hidden" name="cycle" value="monthly">
                        <button class="text-sm text-red-600 underline">Downgrade to Free</button>
                    </form>
                </div>
            @else
                <p>No subscription yet — pick a plan below.</p>
            @endif
        </div>

        {{-- Pending payment --}}
        @if ($pendingPayment)
            <div class="bg-yellow-50 border border-yellow-300 rounded-lg p-6">
                <h3 class="font-semibold">Pending payment — IDR {{ number_format($pendingPayment->amount) }}</h3>
                <p class="text-sm text-gray-600">Expires {{ $pendingPayment->expires_at?->format('H:i') }}.
                    <a class="text-indigo-600 underline" href="{{ route('billing.pay', $pendingPayment) }}">Show QR code →</a></p>
            </div>
        @endif

        {{-- Plans --}}
        <div class="grid md:grid-cols-4 gap-4">
            @foreach ($plans as $plan)
                <div class="bg-white rounded-lg shadow p-5 flex flex-col {{ $subscription?->plan_id === $plan->id ? 'ring-2 ring-indigo-500' : '' }}">
                    <h3 class="font-bold text-lg">{{ $plan->name }}</h3>
                    <p class="text-sm text-gray-500 mb-2">{{ $plan->description }}</p>
                    <div class="text-xl font-bold mb-1">
                        @if ($plan->price_monthly > 0)
                            Rp {{ number_format($plan->price_monthly) }}<span class="text-sm text-gray-500">/mo</span>
                        @else
                            Free
                        @endif
                    </div>
                    <ul class="text-sm text-gray-600 mb-4 flex-1">
                        @foreach ($plan->features ?? [] as $f)
                            <li>✓ {{ $f }}</li>
                        @endforeach
                    </ul>
                    @if ($subscription?->plan_id !== $plan->id)
                        <form method="POST" action="{{ route('billing.checkout') }}">
                            @csrf
                            <input type="hidden" name="plan" value="{{ $plan->slug }}">
                            <input type="hidden" name="cycle" value="monthly">
                            <button class="w-full px-3 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">
                                {{ $plan->price_monthly > 0 ? 'Subscribe' : 'Switch to Free' }}
                            </button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- History --}}
        <div class="grid md:grid-cols-2 gap-6">
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-semibold mb-3">Invoices</h3>
                <table class="w-full text-sm">
                    @forelse ($invoices as $inv)
                        <tr class="border-t"><td class="py-1 font-mono text-xs">{{ $inv->invoice_number }}</td>
                            <td>IDR {{ number_format($inv->amount) }}</td>
                            <td><span class="px-2 rounded-full {{ $inv->status === 'paid' ? 'bg-green-100 text-green-700' : 'bg-gray-100' }}">{{ $inv->status }}</span></td></tr>
                    @empty
                        <tr><td class="text-gray-500 py-2">No invoices yet.</td></tr>
                    @endforelse
                </table>
            </div>
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-semibold mb-3">Payment history</h3>
                <table class="w-full text-sm">
                    @forelse ($payments as $pay)
                        <tr class="border-t"><td class="py-1 font-mono text-xs">{{ $pay->order_id }}</td>
                            <td>IDR {{ number_format($pay->amount) }}</td>
                            <td><span class="px-2 rounded-full {{ $pay->status === 'paid' ? 'bg-green-100 text-green-700' : ($pay->status === 'pending' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100') }}">{{ $pay->status }}</span></td></tr>
                    @empty
                        <tr><td class="text-gray-500 py-2">No payments yet.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
