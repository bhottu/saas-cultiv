<x-admin-shell :title="__('Platform Admin')" :subtitle="__('Cross-workspace monitoring.')">
    <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
        <x-kpi-card variant="admin" :label="__('Total users')" :value="number_format($stats['users_total'])"
                    :hint="number_format($stats['users_verified']).' '.__('verified')" />
        <x-kpi-card variant="admin" :label="__('Active workspaces')" :value="number_format($stats['workspaces_active'])"
                    :hint="number_format($stats['workspaces_deleted']).' '.__('soft-deleted')" />
        <x-kpi-card variant="admin" :label="__('MRR (IDR)')" :value="\App\Services\Money::formatRupiah($stats['mrr'])"
                    :hint="number_format($stats['subscriptions_active']).' '.__('active subscriptions')" />
        <x-kpi-card variant="admin" :label="__('Sales value')" :value="\App\Services\Money::format($stats['sales_value'])"
                    :hint="number_format($stats['sales_total']).' '.__('sales')" />
    </div>

    <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
        <x-kpi-card variant="admin" :label="__('Products')" :value="number_format($stats['products_total'])" />
        <x-kpi-card variant="admin" :label="__('Customers')" :value="number_format($stats['customers_total'])" />
        <x-kpi-card variant="admin" :label="__('Suppliers')" :value="number_format($stats['suppliers_total'])" />
        <x-kpi-card variant="admin" :label="__('Warehouses')" :value="number_format($stats['warehouses_total'])"
                    :hint="number_format($stats['inventory_units']).' '.__('units on hand')" />
    </div>

    <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
        <x-kpi-card variant="admin" :label="__('Subscriptions')" :value="number_format($stats['subscriptions_total'])" />
        <x-kpi-card variant="admin" :label="__('Purchases (received)')" :value="\App\Services\Money::format($stats['purchases_value'])"
                    :hint="number_format($stats['purchases_total']).' '.__('orders')" />
        <x-kpi-card variant="admin" :label="__('Payments pending')" :value="number_format($stats['payments_pending'])"
                    :tone="$stats['payments_pending'] > 0 ? 'negative' : 'default'" />
        <x-kpi-card variant="admin" :label="__('Payments failed / expired')" :value="number_format($stats['payments_failed'])"
                    :hint="number_format($stats['payments_paid']).' '.__('paid')"
                    :tone="$stats['payments_failed'] > 0 ? 'negative' : 'positive'" />
    </div>

    <div class="admin-card p-5 sm:p-6">
        <h3 class="mb-3 font-semibold text-gray-900">{{ __('Workspaces by plan') }}</h3>
        @if ($workspacesByPlan)
            <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($workspacesByPlan as $name => $total)
                    <div class="rounded-lg border border-gray-200 p-3">
                        <dt class="text-xs uppercase tracking-wide text-gray-500">{{ $name }}</dt>
                        <dd class="mt-1 text-lg font-bold text-gray-900">{{ number_format($total) }}</dd>
                    </div>
                @endforeach
            </dl>
        @else
            <p class="text-sm text-gray-500">{{ __('No active subscriptions.') }}</p>
        @endif
    </div>

    <x-admin-table :empty="__('No webhook events.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('Event') }}</th>
                <th class="px-4 py-3">{{ __('Status') }}</th>
                <th class="px-4 py-3">{{ __('Signature') }}</th>
                <th class="px-4 py-3">{{ __('When') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($webhookEvents as $event)
                <tr>
                    <td class="px-4 py-3 font-mono text-xs">{{ $event->event_id }}</td>
                    <td class="px-4 py-3">{{ $event->status }}</td>
                    <td class="px-4 py-3">{{ $event->signature_valid ? '✓' : '✗' }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ $event->created_at?->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-6 text-gray-500">{{ __('No webhook events.') }}</td></tr>
            @endforelse
        </tbody>
    </x-admin-table>

    <div class="admin-card p-5 sm:p-6">
        <h3 class="mb-3 font-semibold text-gray-900">{{ __('Recent audit log') }}</h3>
        <ul class="space-y-1 text-sm">
            @forelse ($recentAudit as $log)
                <li class="flex justify-between border-b pb-1">
                    <span class="font-mono">{{ $log->action }}</span>
                    <span class="text-gray-500">{{ $log->created_at?->diffForHumans() }}</span>
                </li>
            @empty
                <li class="text-gray-500">{{ __('Nothing recorded yet.') }}</li>
            @endforelse
        </ul>
    </div>
</x-admin-shell>