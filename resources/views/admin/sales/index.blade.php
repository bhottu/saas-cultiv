<x-admin-shell :title="__('Sales')" :subtitle="__('Orders across every workspace. Read-only — no transaction can be modified here.')">
    <x-admin-filters :submit="route('admin.sales.index')" :columns="4">
        <div>
            <x-input-label for="search" :value="__('Search')" />
            <x-text-input id="search" name="search" class="mt-1 block w-full"
                          :value="$filters['search'] ?? ''" :placeholder="__('Invoice number')" />
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
            <x-input-label for="status" :value="__('Order status')" />
            <select id="status" name="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="payment_status" :value="__('Payment status')" />
            <select id="payment_status" name="payment_status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('All payment states') }}</option>
                @foreach ($paymentStatuses as $state)
                    <option value="{{ $state }}" @selected(($filters['payment_status'] ?? '') === $state)>{{ ucfirst(str_replace('_', ' ', $state)) }}</option>
                @endforeach
            </select>
        </div>
    </x-admin-filters>

    <x-admin-table :paginator="$sales" :empty="__('No sales found.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('Invoice') }}</th>
                <th class="px-4 py-3">{{ __('Workspace') }}</th>
                <th class="px-4 py-3">{{ __('Customer') }}</th>
                <th class="px-4 py-3">{{ __('Items') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Total') }}</th>
                <th class="px-4 py-3">{{ __('Order') }}</th>
                <th class="px-4 py-3">{{ __('Payment') }}</th>
                <th class="px-4 py-3">{{ __('Sold at') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($sales as $sale)
                <tr>
                    <td class="px-4 py-3 font-mono text-xs">{{ $sale->invoice_number }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $sale->tenant?->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $sale->customer?->name ?? __('Walk-in Customer') }}</td>
                    <td class="px-4 py-3">{{ $sale->items_count }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ \App\Services\Money::format($sale->total) }}</td>
                    <td class="px-4 py-3">{{ ucfirst($sale->status) }}</td>
                    <td class="px-4 py-3">{{ ucfirst(str_replace('_', ' ', $sale->payment_status)) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ $sale->sold_at?->format('d M Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-6 text-gray-500">{{ __('No sales found.') }}</td></tr>
            @endforelse
        </tbody>
    </x-admin-table>
</x-admin-shell>