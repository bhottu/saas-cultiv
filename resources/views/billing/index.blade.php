<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Billing') }}</h2>
    </x-slot>

    <div class="py-12 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if (session('success'))
            <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="bg-red-100 text-red-800 p-3 rounded">{{ session('error') }}</div>
        @endif
        {{-- The flash is read from the session, not from a `$status` variable the
             controller never passed. Referring to $status here rendered an empty
             banner, which is why a refused action showed no reason at all. --}}
        @if (session('status'))
            @php $billingStatus = session('status'); @endphp
            <div class="p-3 rounded {{ ($billingStatus['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}" role="alert">
                {{ $billingStatus['message'] ?? '' }}
            </div>
        @endif

        {{-- Rejected requests must never fail silently.
             The pending-payment modal posts `intent` from its submit button, which
             is not a form field this page renders an <x-input-error> for. Without
             this block a rejected request returned the customer to a page that
             looked untouched, with nothing explaining why. --}}
        @if ($errors->any())
            <div class="bg-red-100 text-red-800 p-3 rounded" role="alert">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
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

        {{-- "You already have a payment waiting" confirmation.
             Shown instead of a 429 when Subscribe is pressed while an unsettled payment
             exists. The plan and cycle the customer actually chose are carried in the form
             so either button continues that exact intent. The payment id is re-validated
             server-side on submit — this is a convenience prompt, not the enforcement. --}}
        @if ($pendingCheckout = session('pending_checkout'))
            <x-modal name="pending-checkout" :show="true" maxWidth="lg">
                {{-- Why two forms instead of one form with two submit buttons:
                     `intent` used to ride on the submitter's name/value. The browser
                     builds a form's entry list AFTER the submit event, and any handler
                     that disables the button in that window (this app does, globally)
                     silently removes it — the request then arrived without `intent` and
                     was rejected with "The intent field is required." Carrying it in a
                     hidden input inside its own form makes the two choices independent of
                     the button's disabled state, of Alpine, and of the JS bundle. --}}
                {{-- ?? on every key: a session flashed by the previous release survives the deploy, and
                     an undefined key here would render a 500 on /billing itself. --}}
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('Payment pending') }}</h3>
                    <p class="mt-2 text-sm text-gray-600">
                        {{ __('You have a payment that has not been completed yet.') }}<br>
                        {{ __('Would you like to continue that payment, or cancel it and create a new one?') }}
                    </p>
                    <p class="mt-3 text-sm text-gray-500">
                        {{ \App\Services\Money::formatRupiah($pendingCheckout['amount'] ?? 0) }}
                    </p>
                </div>

                <div class="bg-gray-50 px-6 py-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    {{-- `contents` keeps each <form> out of the flex layout so the two
                         buttons sit exactly where the old single-form row put them. --}}
                    <form method="POST" action="{{ route('billing.checkout.resolve') }}" class="contents">
                        @csrf
                        <input type="hidden" name="intent" value="continue">
                        <input type="hidden" name="payment_id" value="{{ $pendingCheckout['payment_id'] ?? '' }}">
                        <input type="hidden" name="invoice_id" value="{{ $pendingCheckout['invoice_id'] ?? '' }}">
                        <input type="hidden" name="plan" value="{{ $pendingCheckout['plan'] ?? '' }}">
                        <input type="hidden" name="cycle" value="{{ $pendingCheckout['cycle'] ?? 'monthly' }}">
                        <x-secondary-button type="submit">{{ __('Continue payment') }}</x-secondary-button>
                    </form>

                    <form method="POST" action="{{ route('billing.checkout.resolve') }}" class="contents">
                        @csrf
                        <input type="hidden" name="intent" value="replace">
                        <input type="hidden" name="payment_id" value="{{ $pendingCheckout['payment_id'] ?? '' }}">
                        <input type="hidden" name="invoice_id" value="{{ $pendingCheckout['invoice_id'] ?? '' }}">
                        <input type="hidden" name="plan" value="{{ $pendingCheckout['plan'] ?? '' }}">
                        <input type="hidden" name="cycle" value="{{ $pendingCheckout['cycle'] ?? 'monthly' }}">
                        <x-danger-button type="submit">{{ __('Cancel & create new') }}</x-danger-button>
                    </form>
                </div>
            </x-modal>
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
