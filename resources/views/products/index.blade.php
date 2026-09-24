<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Products') }}</h2>
            <a href="{{ route('products.create') }}"
               class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700">
                {{ __('Add product') }}
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

        {{-- Filters --}}
        <form method="GET" action="{{ route('products.index') }}"
              class="bg-white shadow rounded-lg p-6 grid gap-4 md:grid-cols-4">
            <div class="md:col-span-2">
                <x-input-label for="search" :value="__('Search')" />
                <x-text-input id="search" name="search" type="text" class="mt-1 block w-full"
                              :value="$filters['search'] ?? ''"
                              placeholder="{{ __('Name, SKU or barcode') }}" />
            </div>

            <div>
                <x-input-label for="category_id" :value="__('Category')" />
                <select id="category_id" name="category_id"
                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) ($filters['category_id'] ?? '') === (string) $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="brand_id" :value="__('Brand')" />
                <select id="brand_id" name="brand_id"
                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    <option value="">{{ __('All brands') }}</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected((string) ($filters['brand_id'] ?? '') === (string) $brand->id)>
                            {{ $brand->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="md:col-span-4 flex flex-wrap items-center gap-6">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="active_only" value="1" class="rounded border-gray-300 text-indigo-600 shadow-sm"
                           @checked(! empty($filters['active_only']))>
                    {{ __('Active only') }}
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="show_inactive" value="1" class="rounded border-gray-300 text-indigo-600 shadow-sm"
                           @checked(! empty($filters['show_inactive']))>
                    {{ __('Inactive only') }}
                </label>
                <button class="px-4 py-2 bg-gray-800 text-white text-sm rounded-lg hover:bg-gray-700">{{ __('Filter') }}</button>
                <a href="{{ route('products.index') }}" class="text-sm text-gray-500 underline">{{ __('Reset') }}</a>
            </div>
        </form>

        {{-- Listing --}}
        <div class="bg-white shadow rounded-lg overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-4 py-3">{{ __('Product') }}</th>
                        <th class="px-4 py-3">{{ __('Category') }}</th>
                        <th class="px-4 py-3">{{ __('Brand') }}</th>
                        <th class="px-4 py-3">{{ __('Unit') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Cost') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Price') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($products as $product)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('products.show', $product) }}" class="font-medium text-indigo-700 hover:underline">
                                    {{ $product->name }}
                                </a>
                                <div class="text-xs text-gray-500">
                                    {{ $product->sku ?: '—' }}
                                    @if ($product->barcode) · {{ $product->barcode }} @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">{{ $product->category?->name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $product->brand?->name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $product->unit }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ \App\Services\Money::format($product->purchase_price) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ \App\Services\Money::format($product->selling_price) }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $product->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $product->is_active ? __('Active') : __('Inactive') }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-3">
                                    <a href="{{ route('products.edit', $product) }}" class="text-indigo-600 hover:underline">{{ __('Edit') }}</a>
                                    <form method="POST" action="{{ route('products.destroy', $product) }}"
                                          onsubmit="return confirm('Delete this product?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-red-600 hover:underline">{{ __('Delete') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-6 text-gray-500">
                                {{ __('No products yet.') }}
                                <a href="{{ route('products.create') }}" class="text-indigo-600 underline">{{ __('Add the first one') }}</a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $products->links() }}</div>
    </div>
</x-app-layout>