<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Complete your payment</h2>
    </x-slot>

    <div class="py-12 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg shadow p-8 text-center" x-data="paymentPoller('{{ route('billing.payment.status', $payment) }}')">
            <h3 class="text-lg font-bold mb-1">Scan QRIS to pay</h3>
            <p class="text-sm text-gray-500 mb-4">Amount: <strong>Rp {{ number_format($payment->amount) }}</strong></p>

            {{-- QR code served from provider URL; no API credentials ever reach the browser. --}}
            @php $qr = $payment->payload['create']['qris_url'] ?? null; @endphp
            @if ($qr)
                <img src="{{ $qr }}" alt="QRIS payment code" class="mx-auto w-56 h-56 border rounded">
            @else
                <p class="text-gray-500">QR code unavailable — try creating a new payment.</p>
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
