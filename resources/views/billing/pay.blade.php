<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Complete your payment</h2>
    </x-slot>

    <div class="py-12 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg shadow p-8 text-center" x-data="paymentPoller('{{ route('billing.payment.status', $payment) }}')">
            @php
                // Decided once, server-side: what artefacts did this gateway return?
                $qrImage = $payment->qrImageUrl();
                $qrString = $qrImage ? null : $payment->qrisPayloadString();
                $checkoutUrl = $payment->checkoutUrl();
                // Hosted checkout only when there is a checkout_url AND no QR to show —
                // otherwise the QR stays the primary path and the link is a fallback.
                $hostedOnly = $checkoutUrl !== null && $qrImage === null && $qrString === null;
            @endphp
            <h3 class="text-lg font-bold mb-1">{{ $hostedOnly ? __('Open the payment page to complete checkout') : 'Scan QRIS to pay' }}</h3>
            <p class="text-sm text-gray-500 mb-4">Amount: <strong>{{ \App\Services\Money::formatRupiah($payment->amount) }}</strong></p>

            {{-- The QR is QRIS.PW's own resource, passed through byte-for-byte:
                 Payment::qrImageUrl() reads the stored create-payment response and
                 returns it verbatim, so the scanned code is the provider's code.
                 Cultiv never assembles, re-encodes or rebuilds a QRIS payload. --}}
            @if ($qrImage)
                <img src="{{ $qrImage }}" alt="QRIS payment code" class="mx-auto w-56 h-56 border rounded">
            @elseif ($qrString)
                {{-- The provider sent a QRIS payload but no image URL. The payload is
                     shown verbatim so the payment is still completable and so the
                     response contract can be confirmed; no QR is invented here. --}}
                <div class="mx-auto w-56 h-56 border rounded p-2 overflow-auto text-left">
                    <p class="text-xs text-gray-500">QRIS code from the payment provider:</p>
                    <p class="text-xs font-mono break-all">{{ $qrString }}</p>
                </div>
            @elseif ($checkoutUrl)
                {{-- No QR artefact, but Kasera handed us a hosted checkout page: that
                     page renders the QR / payment options, and the URL is the
                     provider's own, passed through verbatim. --}}
                <p class="text-gray-500">{{ __('Complete this payment on the hosted payment page.') }}</p>
            @else
                <p class="text-gray-500">QR code unavailable — try creating a new payment.</p>
            @endif

            @if ($checkoutUrl)
                <a href="{{ $checkoutUrl }}" target="_blank" rel="noopener noreferrer"
                   class="inline-block mt-3 px-5 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700">{{ __('Open payment page') }}</a>
            @endif

            <div class="mt-4 text-sm">
                Order: <span class="font-mono">{{ $payment->order_id }}</span><br>
                Expires: <strong x-text="expiresAt ? new Date(expiresAt).toLocaleTimeString() : '{{ $payment->expires_at?->format('H:i') }}'"></strong>
            </div>

            <div class="mt-6" x-show="status === 'pending'">
                <p class="text-yellow-700 bg-yellow-50 py-2 rounded">Waiting for payment…
                    <span x-text="secondsLeft ? Math.ceil(secondsLeft) + 's left' : ''"></span></p>
                <button @click="checkNow()" class="mt-3 px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">I've paid — check status</button>
            </div>
            <div class="mt-6 text-green-700 bg-green-50 py-3 rounded font-semibold" x-show="status === 'paid'" x-cloak>
                ✓ Payment confirmed! Your subscription is active.
            </div>
            <div class="mt-6 text-red-700 bg-red-50 py-3 rounded" x-show="['expired','failed','cancelled'].includes(status)" x-cloak>
                Payment <span x-text="status"></span>. <a class="underline" href="{{ route('billing.index') }}">Create a new payment</a>.
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        Alpine.data('paymentPoller', (statusUrl) => ({
            status: 'pending',
            secondsLeft: null,
            expiresAt: null,
            init() {
                // NOTE: polling only reads server state — the backend is the single source of truth.
                this.timer = setInterval(() => this.poll(), 5000);
                this.poll();
            },
            async poll() {
                if (this.status !== 'pending') return clearInterval(this.timer);
                try {
                    const res = await fetch(statusUrl);
                    const data = await res.json();
                    this.status = data.status;
                    this.secondsLeft = data.seconds_left;
                    this.expiresAt = data.expires_at;
                    if (data.status === 'paid') clearInterval(this.timer);
                } catch (e) { /* transient network errors: keep polling */ }
            },
            async checkNow() {
                // Ask backend to verify directly with QRIS.PW (rate-limited server-side).
                await fetch('{{ route('billing.payment.check', $payment) }}', { method: 'POST' });
                this.poll();
            },
        }));
    </script>
    @endpush
</x-app-layout>
