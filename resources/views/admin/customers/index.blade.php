<x-admin-shell :title="__('Customers')" :subtitle="__('Customers across every workspace. Rows are never merged across workspaces.')">
    <x-admin-filters :submit="route('admin.customers.index')" :columns="3">
        <div>
            <x-input-label for="search" :value="__('Search')" />
            <x-text-input id="search" name="search" class="mt-1 block w-full"
                          :value="$filters['search'] ?? ''" :placeholder="__('Name, phone or email')" />
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
        <div class="flex flex-wrap items-end gap-4">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="active_only" value="1" class="rounded border-gray-300 text-indigo-600"
                       @checked(! empty($filters['active_only']))> {{ __('Active') }}
            </label>
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="inactive_only" value="1" class="rounded border-gray-300 text-indigo-600"
                       @checked(! empty($filters['inactive_only']))> {{ __('Inactive') }}
            </label>
        </div>
    </x-admin-filters>

    <x-admin-table :paginator="$customers" :empty="__('No customers found.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('Customer') }}</th>
                <th class="px-4 py-3">{{ __('Workspace') }}</th>
                <th class="px-4 py-3">{{ __('Phone') }}</th>
                <th class="px-4 py-3">{{ __('Email') }}</th>
                <th class="px-4 py-3">{{ __('Orders') }}</th>
                <th class="px-4 py-3">{{ __('Status') }}</th>
                <th class="px-4 py-3">{{ __('Created') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($customers as $customer)
                <tr>
                    <td class="px-4 py-3 font-medium text-gray-900">{{ $customer->name }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $customer->tenant?->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $customer->phone ?: '—' }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $customer->email ?: '—' }}</td>
                    <td class="px-4 py-3">{{ $customer->sales_count }}</td>
                    <td class="px-4 py-3">{{ $customer->is_active ? __('Active') : __('Inactive') }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ $customer->created_at?->format('d M Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-gray-500">{{ __('No customers found.') }}</td></tr>
            @endforelse
        </tbody>
    </x-admin-table>
</x-admin-shell>