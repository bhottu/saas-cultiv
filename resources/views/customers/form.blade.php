<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ $pageTitle }}</h2>
            <a href="{{ route('customers.index') }}" class="shrink-0 text-sm text-gray-600 underline">{{ __('Back to customers') }}</a>
        </div>
    </x-slot>

    @php
        // Money is stored as cents (App\Services\Money); the input shows the user-facing amount.
        $amount = fn ($cents) => $cents ? number_format($cents / 100, 2, '.', '') : '';
        $isActive = old('is_active', $customer->exists ? $customer->is_active : true);
    @endphp

    <div class="mx-auto max-w-3xl space-y-6 py-12 sm:px-6 lg:px-8">
        @if ($errors->any())
            <div class="rounded-lg bg-red-100 p-3 text-red-800">
                <ul class="list-inside list-disc space-y-1 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $submitUrl }}" class="space-y-6 rounded-lg bg-white p-6 shadow">
            @csrf
            @if ($customer->exists)
                @method('PUT')
            @endif

            <div class="grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <x-input-label for="name" :value="__('Name')" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                  :value="old('name', $customer->name)" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="phone" :value="__('Phone')" />
                    <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full"
                                  :value="old('phone', $customer->phone)" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="email" :value="__('Email')" />
                    <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                                  :value="old('email', $customer->email)" />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="address" :value="__('Address')" />
                    <x-text-input id="address" name="address" type="text" class="mt-1 block w-full"
                                  :value="old('address', $customer->address)" />
                    <x-input-error :messages="$errors->get('address')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="credit_limit" :value="__('Credit limit (Rp)')" />
                    <x-text-input id="credit_limit" name="credit_limit" type="number" step="0.01" min="0"
                                  class="mt-1 block w-full" :value="old('credit_limit', $amount($customer->credit_limit))" />
                    <x-input-error :messages="$errors->get('credit_limit')" class="mt-2" />
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="notes" :value="__('Notes')" />
                    <textarea id="notes" name="notes" rows="3"
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes', $customer->notes) }}</textarea>
                    <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                </div>
            </div>

            <div class="flex flex-wrap gap-6">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           class="rounded border-gray-300 text-indigo-600 shadow-sm"
                           @checked($isActive)>
                    {{ __('Active') }}
                </label>
            </div>

            <div class="flex items-center gap-3">
                <x-primary-button>{{ $customer->exists ? __('Update customer') : __('Create customer') }}</x-primary-button>
                <a href="{{ route('customers.index') }}" class="text-sm text-gray-600 underline">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
</x-app-layout>