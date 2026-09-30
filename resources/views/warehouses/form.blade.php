<x-app-layout>
    <x-slot name="header"><div class="flex items-center justify-between"><h2 class="text-lg font-semibold text-gray-900">{{ $pageTitle }}</h2><a href="{{ route('warehouses.index') }}" class="text-sm underline">{{ __('Back to warehouses') }}</a></div></x-slot>
    <div class="mx-auto max-w-3xl py-12 sm:px-6 lg:px-8"><form method="POST" action="{{ $submitUrl }}" class="space-y-5 rounded-lg bg-white p-6 shadow">@csrf @if($warehouse->exists) @method('PUT') @endif
        <div><x-input-label for="name" :value="__('Name *')"/><x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name',$warehouse->name)" required/><x-input-error :messages="$errors->get('name')" class="mt-2"/></div>
        <div><x-input-label for="code" :value="__('Code')"/><x-text-input id="code" name="code" class="mt-1 block w-full uppercase" :value="old('code',$warehouse->code)"/><x-input-error :messages="$errors->get('code')" class="mt-2"/></div>
        <div><x-input-label for="address" :value="__('Address')"/><textarea id="address" name="address" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">{{ old('address',$warehouse->address) }}</textarea></div>
        <label class="inline-flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active',$warehouse->exists ? $warehouse->is_active : true))> {{ __('Active') }}</label>
        <x-primary-button>{{ $warehouse->exists ? __('Update warehouse') : __('Create warehouse') }}</x-primary-button>
    </form></div>
</x-app-layout>