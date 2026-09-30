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
                        <button class="text-sm text-red-600 underline" @disabled($subscription?->plan?->is_free_tier)>Downgrade to Free</button>
                    </form>
                </div>
            @else
                <p>No subscription yet — pick a plan below.</p>
            @endif
        </div>

        {{-- Unsettled payment notice.
             The controller already decided what this is: a live pending payment, or one
             whose window has closed. The QR link is therefore rendered only when the
             payment is genuinely still payable, and never for an expired one. Dismissal
             posts to the server (dismissed_notifications) so the notice stays gone after
             a refresh — it is a real form post, not a JS visibility toggle, and it does
             not touch the payment or invoice records. --}}
        @if ($pendingPayment)
            @php $noticeExpired = $pendingPayment->status !== 'pending'; @endphp
            <div class="{{ $noticeExpired ? 'bg-gray-50 border-gray-300' : 'bg-yellow-50 border-yellow-300' }} border rounded-lg p-6">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold">
                            {{ $noticeExpired ? 'Payment expired' : 'Pending payment' }} — {{ \App\Services\Money::formatRupiah($pendingPayment->amount) }}
                        </h3>
                        @if ($noticeExpired)
                            <p class="text-sm text-gray-600">Expired — this payment can no longer be completed. Subscribe again to get a new QR code.</p>
                        @else
                            <p class="text-sm text-gray-600">
                                Expires {{ $pendingPayment->expires_at?->format('H:i') }}.
                                <a class="text-indigo-600 underline" href="{{ route('billing.pay', $pendingPayment) }}">Show QR code →</a>
                            </p>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('billing.notice.dismiss') }}">
                        @csrf
                        <input type="hidden" name="key" value="{{ $pendingPayment->id }}">
                        <button type="submit" aria-label="Dismiss payment notice"
                                class="text-gray-500 hover:text-gray-800 text-lg leading-none px-1">&times;</button>
                    </form>
                </div>
            </div>
        @endif

        {{-- Plans --}}
        <div class="grid md:grid-cols-4 gap-4">
            @foreach ($plans as $plan)
                <div class="bg-white rounded-lg shadow p-5 flex flex-col {{ $subscription?->plan_id === $plan->id ? 'ring-2 ring-indigo-500' : '' }}">
                    <h3 class="font-bold text-lg">{{ $plan->name }}</h3>
                    <p class="text-sm text-gray-500 mb-2">{{ $plan->description }}</p>
                    <div class="text-xl font-bold mb-1">
                        {{ \App\Services\Money::formatRupiah($plan->price_monthly) }}<span class="text-sm text-gray-500">/mo</span>
                    </div>
                    {{-- Features + Modules come from one shared component so this page can
                         never disagree with the pricing page or /admin/plans. --}}
                    <div class="mb-4 flex-1">
                        <x-plan-capabilities :plan="$plan" />
                    </div>
                    @if ($subscription?->plan_id !== $plan->id)
                        <form method="POST" action="{{ route('billing.checkout') }}">
                            @csrf
                            <input type="hidden" name="plan" value="{{ $plan->slug }}">
                            <input type="hidden" name="cycle" value="monthly">
                            <x-primary-button data-busy-label="{{ __('Processing payment…') }}">{{ $plan->price_monthly > 0 ? 'Subscribe' : 'Switch to Free' }}</x-primary-button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- History --}}
        <div class="grid md:grid-cols-2 gap-6">
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-semibold mb-3">Invoices</h3>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[28rem] text-sm">
                    @forelse ($invoices as $inv)
                        <tr class="border-t"><td class="py-1 font-mono text-xs">{{ $inv->invoice_number }}</td>
                            <td>{{ \App\Services\Money::formatRupiah($inv->amount) }}</td>
                            <td><span class="px-2 rounded-full {{ $inv->status === 'paid' ? 'bg-green-100 text-green-700' : 'bg-gray-100' }}">{{ $inv->status }}</span></td></tr>
                    @empty
                        <tr><td class="text-gray-500 py-2">No invoices yet.</td></tr>
                    @endforelse
                    </table>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-semibold mb-3">Payment history</h3>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[28rem] text-sm">
                    @forelse ($payments as $pay)
                        <tr class="border-t"><td class="py-1 font-mono text-xs">{{ $pay->order_id }}</td>
                            <td>{{ \App\Services\Money::formatRupiah($pay->amount) }}</td>
                            <td><span class="px-2 rounded-full {{ $pay->status === 'paid' ? 'bg-green-100 text-green-700' : ($pay->status === 'pending' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100') }}">{{ $pay->status }}</span></td></tr>
                    @empty
                        <tr><td class="text-gray-500 py-2">No payments yet.</td></tr>
                    @endforelse
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
