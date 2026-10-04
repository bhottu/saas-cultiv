<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-900">{{ $pageTitle }}</h2>
            <a href="{{ route('expense-categories.index') }}" class="text-sm underline">{{ __('Back to categories') }}</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-3xl py-12 sm:px-6 lg:px-8">
        <form method="POST" action="{{ $submitUrl }}" class="space-y-5 rounded-lg bg-white p-6 shadow">
            @csrf
            @if ($category->exists)
                @method('PUT')
            @endif

            <div>
                <x-input-label for="name" :value="__('Name *')" />
                <x-text-input id="name" name="name" class="mt-1 block w-full" required
                              :value="old('name', $category->name)"
                              placeholder="{{ __('e.g. Utilities') }}" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <label class="inline-flex items-center gap-2 text-sm">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $category->exists ? $category->is_active : true))>
                {{ __('Active') }}
            </label>

            <div class="flex items-center gap-3">
                <x-primary-button>{{ $category->exists ? __('Update category') : __('Create category') }}</x-primary-button>
                <a href="{{ route('expense-categories.index') }}" class="text-sm text-gray-600 underline">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
</x-app-layout>