<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $pageTitle }}</h2>
            <a href="{{ route('products.index') }}" class="text-sm text-gray-600 underline">{{ __('Back to products') }}</a>
        </div>
    </x-slot>

    @php
        // Prices are stored as cents; inputs show the user-facing amount.
        $amount = fn ($cents) => $cents ? number_format($cents / 100, 2, '.', '') : '';
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
            @if ($product->exists)
                @method('PUT')
            @endif

            {{-- Identity --}}
            <div class="grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <x-input-label for="name" :value="__('Name')" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                  :value="old('name', $product->name)" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="sku" :value="__('SKU')" />
                    <x-text-input id="sku" name="sku" type="text" class="mt-1 block w-full"
                                  :value="old('sku', $product->sku)" />
                    <x-input-error :messages="$errors->get('sku')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="barcode" :value="__('Barcode')" />
                    <x-text-input id="barcode" name="barcode" type="text" class="mt-1 block w-full"
                                  :value="old('barcode', $product->barcode)" />
                    <x-input-error :messages="$errors->get('barcode')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="category_id" :value="__('Category')" />
                    <select id="category_id" name="category_id"
                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="">{{ __('— none —') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) old('category_id', $product->category_id) === (string) $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('category_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="brand_id" :value="__('Brand')" />
                    <select id="brand_id" name="brand_id"
                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="">{{ __('— none —') }}</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->id }}" @selected((string) old('brand_id', $product->brand_id) === (string) $brand->id)>
                                {{ $brand->name }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('brand_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="unit" :value="__('Unit')" />
                    <x-text-input id="unit" name="unit" type="text" class="mt-1 block w-full"
                                  :value="old('unit', $product->unit ?: 'pcs')" required />
                    <x-input-error :messages="$errors->get('unit')" class="mt-2" />
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="description" :value="__('Description')" />
                    <textarea id="description" name="description" rows="3"
                              class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('description', $product->description) }}</textarea>
                    <x-input-error :messages="$errors->get('description')" class="mt-2" />
                </div>
            </div>

            {{-- Pricing (stored as cents) --}}
            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <div class="flex items-center gap-1">
                        <x-input-label for="purchase_price" :value="__('Purchase price')" />
                        <x-help-tooltip id="purchase-price-help" :label="__('Purchase price')"
                                        :text="__('The price you pay when buying the product from a supplier, e.g. :example.', ['example' => \App\Services\Money::format(2_000_000, 'IDR', false)])" />
                    </div>
                    <x-text-input id="purchase_price" name="purchase_price" type="number" step="0.01" min="0"
                                  class="mt-1 block w-full" :value="old('purchase_price', $amount($product->purchase_price))" />
                    <x-input-error :messages="$errors->get('purchase_price')" class="mt-2" />
                </div>

                <div>
                    <div class="flex items-center gap-1">
                        <x-input-label for="cost_price" :value="__('Cost price')" />
                        <x-help-tooltip id="cost-price-help" :label="__('Cost price')"
                                        :text="__('The cost of goods for this product once related costs are taken into account, e.g. :example.', ['example' => \App\Services\Money::format(2_500_000, 'IDR', false)])" />
                    </div>
                    <x-text-input id="cost_price" name="cost_price" type="number" step="0.01" min="0"
                                  class="mt-1 block w-full" :value="old('cost_price', $amount($product->cost_price))" />
                    <p class="text-xs text-gray-500 mt-1">{{ __('Defaults to the purchase price.') }}</p>
                    <x-input-error :messages="$errors->get('cost_price')" class="mt-2" />
                </div>

                <div>
                    <div class="flex items-center gap-1">
                        <x-input-label for="selling_price" :value="__('Selling price')" />
                        <x-help-tooltip id="selling-price-help" :label="__('Selling price')"
                                        :text="__('The price you charge your customers, e.g. :example.', ['example' => \App\Services\Money::format(7_500_000, 'IDR', false)])" />
                    </div>
                    <x-text-input id="selling_price" name="selling_price" type="number" step="0.01" min="0"
                                  class="mt-1 block w-full" :value="old('selling_price', $amount($product->selling_price))" />
                    <x-input-error :messages="$errors->get('selling_price')" class="mt-2" />
                </div>
            </div>

            {{-- Stock --}}
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <x-input-label for="minimum_stock" :value="__('Minimum stock')" />
                    <x-text-input id="minimum_stock" name="minimum_stock" type="number" min="0"
                                  class="mt-1 block w-full" :value="old('minimum_stock', $product->minimum_stock ?? 0)" />
                    <x-input-error :messages="$errors->get('minimum_stock')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="max_stock" :value="__('Maximum stock')" />
                    <x-text-input id="max_stock" name="max_stock" type="number" min="0"
                                  class="mt-1 block w-full" :value="old('max_stock', $product->max_stock)" />
                    <x-input-error :messages="$errors->get('max_stock')" class="mt-2" />
                </div>
            </div>

            {{-- Flags --}}
            <div class="flex flex-wrap gap-6">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="hidden" name="track_inventory" value="0">
                    <input type="checkbox" name="track_inventory" value="1"
                           class="rounded border-gray-300 text-indigo-600 shadow-sm"
                           @checked(old('track_inventory', $product->track_inventory))>
                    {{ __('Track inventory') }}
                </label>

                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           class="rounded border-gray-300 text-indigo-600 shadow-sm"
                           @checked(old('is_active', $product->is_active))>
                    {{ __('Active') }}
                </label>
            </div>

            <div class="flex items-center gap-3">
                <x-primary-button>{{ $product->exists ? __('Update product') : __('Create product') }}</x-primary-button>
                <a href="{{ route('products.index') }}" class="text-sm text-gray-600 underline">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
</x-app-layout>