<x-admin-shell :title="__('Inventory')" :subtitle="__('Stock on hand across every workspace and warehouse.')">
    <x-admin-filters :submit="route('admin.inventory.index')" :columns="4">
        <div>
            <x-input-label for="search" :value="__('Search')" />
            <x-text-input id="search" name="search" class="mt-1 block w-full"
                          :value="$filters['search'] ?? ''" :placeholder="__('Product name or SKU')" />
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
            <x-input-label for="warehouse_id" :value="__('Warehouse')" />
            <select id="warehouse_id" name="warehouse_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('All warehouses') }}</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected((string) ($filters['warehouse_id'] ?? '') === (string) $warehouse->id)>
                        {{ $warehouse->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-wrap items-end gap-4">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="in_stock" value="1" class="rounded border-gray-300 text-indigo-600"
                       @checked(! empty($filters['in_stock']))> {{ __('In stock') }}
            </label>
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="low_stock" value="1" class="rounded border-gray-300 text-indigo-600"
                       @checked(! empty($filters['low_stock']))> {{ __('Low stock') }}
            </label>
        </div>
    </x-admin-filters>

    <x-admin-table :paginator="$balances" :empty="__('No stock balances found.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('Product') }}</th>
                <th class="px-4 py-3">{{ __('Workspace') }}</th>
                <th class="px-4 py-3">{{ __('Warehouse') }}</th>
                <th class="px-4 py-3 text-right">{{ __('On hand') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Incoming') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Outgoing') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($balances as $balance)
                <tr>
                    <td class="px-4 py-3 font-medium text-gray-900">
                        {{ $balance->product?->name ?? '—' }}
                        <div class="text-xs text-gray-500">{{ $balance->product?->sku ?: '—' }}</div>
                    </td>
                    <td class="px-4 py-3 text-gray-600">{{ $balance->product?->tenant?->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $balance->warehouse?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-right font-medium">{{ (int) $balance->quantity }}</td>
                    <td class="px-4 py-3 text-right text-gray-500">{{ (int) $balance->incoming }}</td>
                    <td class="px-4 py-3 text-right text-gray-500">{{ (int) $balance->outgoing }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-gray-500">{{ __('No stock balances found.') }}</td></tr>
            @endforelse
        </tbody>
    </x-admin-table>
</x-admin-shell>