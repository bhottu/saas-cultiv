<x-admin-shell :title="__('Products')" :subtitle="__('Products across every workspace.')">
    <x-admin-filters :submit="route('admin.products.index')" :columns="3">
        <div>
            <x-input-label for="search" :value="__('Search')" />
            <x-text-input id="search" name="search" class="mt-1 block w-full"
                          :value="$filters['search'] ?? ''" :placeholder="__('Name, SKU or barcode')" />
        </div>
        <div>
            <x-input-label for="tenant_id" :value="__('Workspace')" />
            <select id="tenant_id" name="tenant_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('All workspaces') }}</option>
                @foreach ($workspaces as $workspace)
                    <option value="{{ $workspace->id }}" @selected((string) ($filters['tenant_id'] ?? '') === (string) $workspace->id)>
                        {{ $workspace->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="category_id" :value="__('Category')" />
            <select id="category_id" name="category_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('All categories') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected((string) ($filters['category_id'] ?? '') === (string) $category->id)>
                        {{ $category->name }}
                    </option>
                @endforeach
            </select>
        </div>
    </x-admin-filters>

    <x-admin-table :paginator="$products" :empty="__('No products found.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('Product') }}</th>
                <th class="px-4 py-3">{{ __('Workspace') }}</th>
                <th class="px-4 py-3">{{ __('Category') }}</th>
                <th class="px-4 py-3">{{ __('Brand') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Stock') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Selling price') }}</th>
                <th class="px-4 py-3">{{ __('Status') }}</th>
                <th class="px-4 py-3">{{ __('Created') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($products as $product)
                <tr>
                    <td class="px-4 py-3 font-medium text-gray-900">
                        {{ $product->name }}
                        <div class="text-xs text-gray-500">{{ $product->sku ?: '—' }}</div>
                    </td>
                    <td class="px-4 py-3 text-gray-600">{{ $product->tenant?->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $product->category?->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $product->brand?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-right">{{ (int) $product->stock_balances_sum_quantity }} {{ $product->unit }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right">{{ \App\Services\Money::format($product->selling_price) }}</td>
                    <td class="px-4 py-3">{{ $product->is_active ? __('Active') : __('Inactive') }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ $product->created_at?->format('d M Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-6 text-gray-500">{{ __('No products found.') }}</td></tr>
            @endforelse
        </tbody>
    </x-admin-table>
</x-admin-shell>