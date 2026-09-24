<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $pageTitle }}</h2>
            <a href="{{ route('categories.index') }}" class="text-sm text-gray-600 underline">{{ __('Back to categories') }}</a>
        </div>
    </x-slot>

    @php
        // New records default to active (matches the is_active column default).
        $isActive = old('is_active', $category->exists ? $category->is_active : true);
    @endphp

    <div class="py-12 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if ($errors->any())
            <div class="bg-red-100 text-red-800 p-3 rounded">
                <ul class="list-disc list-inside text-sm space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $submitUrl }}" class="bg-white shadow rounded-lg p-6 space-y-6">
            @csrf
            @if ($category->exists)
                @method('PUT')
            @endif

            <div>
                <x-input-label for="name" :value="__('Name')" />
                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                              :value="old('name', $category->name)" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="description" :value="__('Description')" />
                <textarea id="description" name="description" rows="3"
                          class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('description', $category->description) }}</textarea>
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
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
                <x-primary-button>{{ $category->exists ? __('Update category') : __('Create category') }}</x-primary-button>
                <a href="{{ route('categories.index') }}" class="text-sm text-gray-600 underline">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
</x-app-layout>