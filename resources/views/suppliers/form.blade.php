<x-app-layout>
    <x-slot name="header"><div class="flex items-center justify-between"><h2 class="text-lg font-semibold text-gray-900">{{ $pageTitle }}</h2><a href="{{ route('suppliers.index') }}" class="text-sm underline">Back to suppliers</a></div></x-slot>
    @php
        // New records default to active (matches the is_active column default).
        // Matches categories/form and brands/form so the three read identically.
        $isActive = old('is_active', $supplier->exists ? $supplier->is_active : true);
    @endphp

    <div class="mx-auto max-w-3xl py-12 sm:px-6 lg:px-8"><form method="POST" action="{{ $submitUrl }}" class="space-y-5 rounded-lg bg-white p-6 shadow">@csrf @if($supplier->exists) @method('PUT') @endif<div><x-input-label for="name" :value="__('Name *')"/><x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name',$supplier->name)" placeholder="{{ __('e.g. PT Sumber Pangan') }}" required/><x-input-error :messages="$errors->get('name')" class="mt-2"/></div><div class="grid gap-4 sm:grid-cols-2"><div><x-input-label for="phone" :value="__('Phone')"/><x-text-input id="phone" name="phone" class="mt-1 block w-full" :value="old('phone',$supplier->phone)" placeholder="{{ __('e.g. 0215551234') }}"/><x-input-error :messages="$errors->get('phone')" class="mt-2"/></div><div><x-input-label for="email" :value="__('Email')"/><x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email',$supplier->email)" placeholder="{{ __('e.g. sales@sumberpangan.co.id') }}"/><x-input-error :messages="$errors->get('email')" class="mt-2"/></div></div><div><x-input-label for="address" :value="__('Address')"/><textarea id="address" name="address" rows="3" placeholder="{{ __('e.g. Jl. Industri No. 10, Bandung') }}" class="mt-1 block w-full rounded-md border-gray-300">{{ old('address',$supplier->address) }}</textarea></div><div><x-input-label for="notes" :value="__('Notes')"/><textarea id="notes" name="notes" rows="3" placeholder="{{ __('e.g. Net 30 payment terms') }}" class="mt-1 block w-full rounded-md border-gray-300">{{ old('notes',$supplier->notes) }}</textarea></div>

            {{-- The wrapper div is load-bearing, not decoration. `<label class="inline-flex">`
                 and <x-primary-button> both render inline-level boxes, so as direct siblings
                 of the form they shared one line and the button sat level with the checkbox.
                 A block-level flex container around the label is exactly what
                 categories/form and brands/form already do, and it keeps them stacked. --}}
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
                <x-primary-button>{{ $supplier->exists ? __('Update supplier') : __('Create supplier') }}</x-primary-button>
                <a href="{{ route('suppliers.index') }}" class="text-sm text-gray-600 underline">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
</x-app-layout>