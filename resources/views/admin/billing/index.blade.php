{{--
    Platform payment gateway selection (/admin/billing).

    One form, one choice: which gateway NEW checkouts are dispatched to. Each card
    shows whether that gateway can actually be selected right now — configured
    credentials come from config (never printed, only yes/no) — together with the
    webhook URL to register with the provider, because "which URL do I paste into
    Kasera?" is the question this screen exists to answer.

    Payments already in flight keep the gateway stored on their own row, which is
    why the subtitle says so out loud: an operator switching gateways must know it
    does not relocate an unpaid QR.
--}}
<x-admin-shell :title="__('Payment settings')"
               :subtitle="__('Which gateway new checkouts use. Payments already created keep the gateway they were created with.')"
               :editable="true">
    <form method="POST" action="{{ route('admin.billing.update') }}" class="space-y-6">
        @csrf
        @method('PUT')

        @if (! ($migrated ?? true))
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800" role="alert">
                {{ __('The payment_settings table is missing — checkouts still use the default gateway. Run "php artisan migrate" on the server, then save here.') }}
            </div>
        @endif

        <section class="rounded-lg bg-white p-6 shadow">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ __('Active gateway') }}</h3>
            <p class="mt-1 text-sm text-gray-500">
                {{ __('New checkouts are sent to the selected gateway until you change it here.') }}
            </p>

            <div class="mt-5 grid gap-4 md:grid-cols-2">
                @foreach ($gateways as $gateway)
                    @php $isSelected = old('active_gateway', $settings->active_gateway) === $gateway['key']; @endphp
                    <label class="flex items-start gap-3 rounded-lg border p-4 cursor-pointer transition
                                  {{ $isSelected ? 'border-indigo-500 ring-1 ring-indigo-500' : 'border-gray-300 hover:border-gray-400' }}">
                        <input type="radio" name="active_gateway" value="{{ $gateway['key'] }}"
                               @checked($isSelected) class="mt-1 text-indigo-600 focus:ring-indigo-500">
                        <span class="min-w-0">
                            <span class="block font-semibold text-gray-800">{{ $gateway['label'] }}</span>
                            <span class="block text-xs mt-0.5 {{ $gateway['configured'] ? 'text-emerald-600' : 'text-amber-600' }}">
                                {{ $gateway['configured'] ? __('Configured') : __('Not configured — set the API key first') }}
                            </span>
                            <span class="block text-xs text-gray-500 mt-1 break-all">
                                {{ __('Webhook URL') }}: {{ $webhookUrls[$gateway['key']] }}
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>

            <x-input-error :messages="$errors->get('active_gateway')" class="mt-3" />

            <div class="mt-6">
                <x-primary-button>{{ __('Save changes') }}</x-primary-button>
            </div>
        </section>
    </form>
</x-admin-shell>