<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Brands') }}</h2>
            <a href="{{ route('brands.create') }}"
               class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700">
                {{ __('Add brand') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if (session('status'))
            @php $status = session('status'); @endphp
            <div class="p-3 rounded {{ ($status['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                {{ $status['message'] ?? '' }}
            </div>
        @endif

        {{-- Filters: same query parameters as BrandsController@index --}}
        <form method="GET" action="{{ route('brands.index') }}"
              class="bg-white shadow rounded-lg p-6 grid gap-4 md:grid-cols-3">
            <div>
                <x-input-label for="search" :value="__('Search')" />
                <x-text-input id="search" name="search" type="text" class="mt-1 block w-full"
                              :value="$search" placeholder="{{ __('Brand name') }}" />
            </div>

            <div class="flex flex-wrap items-center gap-6 md:col-span-2 md:justify-end">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="active_only" value="1" class="rounded border-gray-300 text-indigo-600 shadow-sm"
                           @checked(request()->boolean('active_only'))>
                    {{ __('Active only') }}
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="show_inactive" value="1" class="rounded border-gray-300 text-indigo-600 shadow-sm"
                           @checked(request()->boolean('show_inactive'))>
                    {{ __('Inactive only') }}
                </label>
                <x-primary-button>{{ __('Filter') }}</x-primary-button>
                <a href="{{ route('brands.index') }}" class="text-sm text-gray-600 underline">{{ __('Reset') }}</a>
            </div>
        </form>

        <div class="bg-white shadow rounded-lg overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('Brand') }}</th>
                        <th class="px-4 py-3">{{ __('Slug') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($brands as $brand)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ $brand->name }}</div>
                                @if ($brand->description)
                                    <div class="text-xs text-gray-500">{{ $brand->description }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-500">{{ $brand->slug ?: '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $brand->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $brand->is_active ? __('Active') : __('Inactive') }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-3">
                                    <a href="{{ route('brands.edit', $brand) }}" class="text-indigo-600 hover:underline">{{ __('Edit') }}</a>
                                    <form method="POST" action="{{ route('brands.destroy', $brand) }}"
                                          onsubmit="return confirm('Delete this brand?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-red-600 hover:underline">{{ __('Delete') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-gray-500">
                                {{ __('No brands yet.') }}
                                <a href="{{ route('brands.create') }}" class="text-indigo-600 underline">{{ __('Add the first one') }}</a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $brands->links() }}</div>
    </div>
</x-app-layout>