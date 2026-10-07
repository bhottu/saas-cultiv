<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Payment') }}</h2>
    </x-slot>

    <div class="py-12 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg shadow p-6 sm:p-8"
             data-initial-status="{{ $payment->status }}"
             data-status-url="{{ route('billing.payment.status', $payment) }}"
             data-check-url="{{ route('billing.payment.check', $payment) }}"
             x-data="{
                 status: 'pending',
                 timer: null,
                 init() {
                     this.status = this.$el.dataset.initialStatus;
                     if (this.status === 'pending') {
                         this.timer = setInterval(() => this.poll(), 5000);
                         this.poll();
                     }
                 },
                 async poll() {
                     if (this.status !== 'pending') return clearInterval(this.timer);
                     try {
                         const response = await fetch(this.$el.dataset.statusUrl);
                         if (!response.ok) throw new Error(`Payment status request failed: ${response.status}`);
                         const data = await response.json();
                         this.status = data.status;
                         if (data.status !== 'pending') clearInterval(this.timer);
                     } catch (error) {
                         console.warn('Unable to refresh payment status; polling will continue.', error);
                     }
                 },
                 async checkNow() {
                     try {
                         const response = await fetch(this.$el.dataset.checkUrl, { method: 'POST' });
                         if (!response.ok) throw new Error(`Payment check request failed: ${response.status}`);
                         await this.poll();
                     } catch (error) {
                         console.warn('Unable to check payment status.', error);
                     }
                 }
             }">
            @php
                $isKasera = $payment->provider === 'kasera';
                $qrImage = $isKasera ? null : $payment->qrImageUrl();
                $qrString = $isKasera ? null : $payment->qrisPayloadString();
                $checkoutUrl = $isKasera ? $payment->checkoutUrl() : null;
                $periodLabel = match ($periodMonths) {
                    1 => __('1 month'),
                    3 => __('3 months'),
                    6 => __('6 months'),
                    12 => __('12 months'),
                    default => null,
                };
            @endphp

            @if ($payment->status === 'pending')
            <section x-show="status === 'pending'">
                <h3 class="text-xl font-bold text-gray-900 mb-2">{{ $plan?->name ?? __('Subscription') }}</h3>

                <div class="space-y-3 text-sm text-gray-700">
                    <p>{{ __('Amount') }}: <strong class="text-lg text-gray-900">{{ \App\Services\Money::formatRupiah($payment->amount) }}</strong></p>

                    @if ($periodLabel)
                        <p>{{ __('Billing period') }}: <strong>{{ $periodLabel }}</strong></p>
                    @endif

                    @if ($isKasera)
                        <p class="text-gray-600">{{ __('You will be redirected to the payment page') }}</p>
                    @elseif ($qrImage || $qrString)
                        <p class="text-gray-600">{{ __('Please scan the QR code to complete your payment') }}</p>
                    @endif
                </div>

                @if ($qrImage)
                    <img src="{{ $qrImage }}" alt="{{ __('QRIS payment code') }}" class="mx-auto mt-5 w-56 h-56 max-w-full border rounded">
                @elseif ($qrString)
                    <div class="mx-auto mt-5 max-w-full rounded border p-3 text-left sm:w-80">
                        <p class="text-xs text-gray-500">{{ __('QRIS payment code') }}</p>
                        <p class="mt-1 break-all font-mono text-xs">{{ $qrString }}</p>
                    </div>
                @elseif (! $isKasera && ! $checkoutUrl)
                    <p class="mt-5 text-sm text-gray-500">{{ __('QR code unavailable — try creating a new payment.') }}</p>
                @endif

                @if ($checkoutUrl)
                    <a href="{{ $checkoutUrl }}" target="_blank" rel="noopener noreferrer"
                       class="inline-flex max-w-full items-center justify-center mt-5 px-5 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700">
                        {{ __('Pay now') }}
                    </a>
                @elseif ($isKasera)
                    <p class="mt-5 text-sm text-gray-500">{{ __('Payment checkout is unavailable. Please return to billing and try again.') }}</p>
                @endif

                <div class="mt-5 text-sm text-gray-600 break-words">
                    {{ __('Order') }}: <span class="font-mono">{{ $payment->order_id }}</span>
                </div>

                <div class="mt-6" x-show="status === 'pending'">
                    <p class="text-yellow-700 bg-yellow-50 py-2 px-3 rounded">{{ __('Waiting for payment…') }}</p>
                    <button @click="checkNow()" class="mt-3 px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">
                        {{ __('I\'ve paid — check status') }}
                    </button>
                </div>
            </section>
            @endif

            @if ($payment->status === 'pending' || in_array($payment->status, ['paid', 'completed', 'successful', 'settlement'], true))
            <section class="text-center" x-show="['paid', 'completed', 'successful', 'settlement'].includes(status)" x-cloak>
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-green-100 text-2xl font-bold text-green-700" aria-hidden="true">✓</div>
                <h3 class="text-xl font-bold text-green-800">{{ __('Payment successful') }}</h3>
                <p class="mt-2 text-sm text-gray-600">{{ __('Your payment was successful.') }}</p>

                <dl class="mt-6 space-y-3 rounded-lg bg-gray-50 p-4 text-left text-sm sm:p-5">
                    @if ($plan)
                        <div class="flex flex-wrap justify-between gap-x-4 gap-y-1">
                            <dt class="text-gray-500">{{ __('Plan') }}</dt>
                            <dd class="font-semibold text-gray-900">{{ $plan->name }}</dd>
                        </div>
                    @endif
                    @if ($periodLabel)
                        <div class="flex flex-wrap justify-between gap-x-4 gap-y-1">
                            <dt class="text-gray-500">{{ __('Billing period') }}</dt>
                            <dd class="font-semibold text-gray-900">{{ $periodLabel }}</dd>
                        </div>
                    @endif
                    <div class="flex flex-wrap justify-between gap-x-4 gap-y-1">
                        <dt class="text-gray-500">{{ __('Amount') }}</dt>
                        <dd class="font-semibold text-gray-900">{{ \App\Services\Money::formatRupiah($payment->amount) }}</dd>
                    </div>
                    <div class="flex flex-wrap justify-between gap-x-4 gap-y-1">
                        <dt class="text-gray-500">{{ __('Status') }}</dt>
                        <dd class="font-semibold text-green-700">{{ __('Successful') }}</dd>
                    </div>
                    <div class="flex flex-wrap justify-between gap-x-4 gap-y-1">
                        <dt class="text-gray-500">{{ __('Order') }}</dt>
                        <dd class="break-all text-right font-mono text-gray-900">{{ $payment->order_id }}</dd>
                    </div>
                </dl>

                <a href="{{ route('billing.index') }}"
                   class="inline-flex max-w-full items-center justify-center mt-6 px-5 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700">
                    {{ __('Open Billing') }}
                </a>
            </section>
            @endif

            @if ($payment->status === 'pending' || in_array($payment->status, ['expired', 'failed', 'cancelled'], true))
            <section class="mt-6 rounded-lg bg-red-50 p-4 text-center text-red-800"
                     x-show="['expired', 'failed', 'cancelled'].includes(status)" x-cloak>
                <h3 class="font-semibold">{{ __('Payment could not be completed.') }}</h3>
                <p class="mt-1 text-sm">{{ __('Your payment could not be completed. You can return to billing and try again.') }}</p>
                <a href="{{ route('billing.index') }}" class="mt-3 inline-block font-semibold underline">{{ __('Open Billing') }}</a>
            </section>
            @endif
        </div>
    </div>

</x-app-layout>
