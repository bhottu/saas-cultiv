<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-900">{{ $pageTitle }}</h2>
            <a href="{{ route('tenants.index') }}" class="text-sm text-gray-600 underline">{{ __('Back to workspaces') }}</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-2xl space-y-6 py-12 sm:px-6 lg:px-8">
        @if ($errors->any())
            <div class="rounded-lg bg-red-100 p-3 text-sm text-red-800" role="alert">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $submitUrl }}" class="rounded-lg bg-white p-6 shadow">
            @csrf
            @method('PUT')

            <div>
                <x-input-label for="name" :value="__('Workspace name *')" />
                <x-text-input id="name" name="name" type="text" required maxlength="100" class="mt-1 block w-full"
                              :value="old('name', $tenant->name)"
                              placeholder="{{ __('e.g. Kopi Nusantara HQ') }}" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <p class="mt-4 text-xs text-gray-500">
                {{ __('The workspace URL stays the same so existing links keep working.') }}
            </p>

            <div class="mt-6 flex items-center gap-3">
                <x-primary-button data-busy-label="{{ __('Updating…') }}">{{ __('Save Changes') }}</x-primary-button>
                <a href="{{ route('tenants.index') }}" class="text-sm text-gray-600 underline">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
</x-app-layout>