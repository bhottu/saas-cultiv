<x-admin-shell :title="$workspace->name" :subtitle="__('Workspace detail')">
    <div class="admin-card p-5 sm:p-6">
        <h3 class="mb-4 font-semibold text-gray-900">{{ __('Workspace') }}</h3>
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Owner') }}</dt>
                <dd class="mt-0.5 text-sm text-gray-900">{{ $workspace->owner?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Plan') }}</dt>
                <dd class="mt-0.5 text-sm text-gray-900">{{ $workspace->activeSubscription?->plan?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Status') }}</dt>
                <dd class="mt-0.5 text-sm text-gray-900">{{ $workspace->trashed() ? __('Soft deleted') : ucfirst($workspace->status) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Created') }}</dt>
                <dd class="mt-0.5 text-sm text-gray-900">{{ $workspace->created_at?->format('d M Y') }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Deleted at') }}</dt>
                <dd class="mt-0.5 text-sm text-gray-900">{{ $workspace->deleted_at?->format('d M Y H:i') ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Products') }}</dt>
                <dd class="mt-0.5 text-sm text-gray-900">{{ number_format($productCount) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Customers') }}</dt>
                <dd class="mt-0.5 text-sm text-gray-900">{{ number_format($workspace->customers_count) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Sales') }}</dt>
                <dd class="mt-0.5 text-sm text-gray-900">{{ number_format($workspace->sales_count) }} · Rp {{ number_format($salesValue) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Purchases') }}</dt>
                <dd class="mt-0.5 text-sm text-gray-900">{{ number_format($workspace->purchases_count) }} · Rp {{ number_format($purchaseValue) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Inventory') }}</dt>
                <dd class="mt-0.5 text-sm text-gray-900">{{ number_format($inventoryUnits) }} {{ __('units on hand') }}</dd>
            </div>
        </dl>

        {{-- Restore is the only write the admin panel offers. The global submit
             loading state turns this into "Processing…" and blocks double submits. --}}
        @if ($workspace->trashed())
            <form method="POST" action="{{ route('admin.workspaces.restore', $workspace->id) }}" class="mt-6"
                  onsubmit="return confirm('Restore this workspace?\n\nIt becomes active again and the owner regains access.')">
                @csrf
                <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    {{ __('Restore workspace') }}
                </button>
            </form>
        @endif
    </div>

    <x-admin-table :empty="__('No members.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('Member') }}</th>
                <th class="px-4 py-3">{{ __('Email') }}</th>
                <th class="px-4 py-3">{{ __('Role') }}</th>
                <th class="px-4 py-3">{{ __('Status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach ($members as $member)
                <tr>
                    <td class="px-4 py-3">{{ $member->name }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $member->email }}</td>
                    <td class="px-4 py-3">{{ $member->pivot->role }}</td>
                    <td class="px-4 py-3">{{ $member->pivot->status }}</td>
                </tr>
            @endforeach
        </tbody>
    </x-admin-table>

    <x-admin-table :empty="__('No warehouses.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('Warehouse') }}</th>
                <th class="px-4 py-3">{{ __('Code') }}</th>
                <th class="px-4 py-3">{{ __('Status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($warehouses as $warehouse)
                <tr>
                    <td class="px-4 py-3">{{ $warehouse->name }}</td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $warehouse->code ?: '—' }}</td>
                    <td class="px-4 py-3">{{ $warehouse->is_active ? __('Active') : __('Inactive') }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="px-4 py-6 text-gray-500">{{ __('No warehouses.') }}</td></tr>
            @endforelse
        </tbody>
    </x-admin-table>
</x-admin-shell>