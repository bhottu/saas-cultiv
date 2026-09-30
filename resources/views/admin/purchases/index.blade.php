<x-admin-shell :title="__('Purchases')" :subtitle="__('Purchase orders across every workspace. Read-only.')">
    <x-admin-filters :submit="route('admin.purchases.index')" :columns="4">
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
            <x-input-label for="status" :value="__('Status')" />
            <select id="status" name="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="from" :value="__('From')" />
            <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$filters['from'] ?? ''" />
        </div>
    </x-admin-filters>

    <x-admin-table :paginator="$purchases" :empty="__('No purchases found.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('Number') }}</th>
                <th class="px-4 py-3">{{ __('Workspace') }}</th>
                <th class="px-4 py-3">{{ __('Supplier') }}</th>
                <th class="px-4 py-3">{{ __('Items') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Total') }}</th>
                <th class="px-4 py-3">{{ __('Status') }}</th>
                <th class="px-4 py-3">{{ __('Ordered') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($purchases as $purchase)
                <tr>
                    <td class="px-4 py-3 font-mono text-xs">{{ $purchase->invoice_number ?: '—' }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $purchase->tenant?->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $purchase->supplier?->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $purchase->items_count }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ \App\Services\Money::format($purchase->total) }}</td>
                    <td class="px-4 py-3">{{ ucfirst($purchase->status) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ $purchase->ordered_at?->format('d M Y') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-gray-500">{{ __('No purchases found.') }}</td></tr>
            @endforelse
        </tbody>
    </x-admin-table>
</x-admin-shell>