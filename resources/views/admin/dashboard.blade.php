<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Platform Admin') }}
        </h2>
    </x-slot>

    <div class="py-12 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="grid grid-cols-2 md:grid-cols-6 gap-4">
            @foreach ([
                'Tenants' => $tenantCount,
                'Users' => $userCount,
                'Active Subs' => $activeSubs,
                'MRR (IDR)' => number_format($mrr),
                'Pending Payments' => $pendingPayments,
                'Failed Payments' => $failedPayments,
            ] as $label => $value)
                <div class="bg-white p-4 rounded-lg shadow">
                    <div class="text-xs text-gray-500 uppercase">{{ $label }}</div>
                    <div class="text-lg font-bold">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="font-semibold mb-3">Recent Webhook Events</h3>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[36rem] text-sm">
                <tr class="text-left text-gray-500"><th class="py-1">Event</th><th>Status</th><th>Signature</th><th>When</th></tr>
                @foreach ($webhookEvents as $event)
                    <tr class="border-t">
                        <td class="py-1 font-mono text-xs">{{ $event->event_id }}</td>
                        <td>{{ $event->status }}</td>
                        <td>{{ $event->signature_valid ? '✓' : '✗' }}</td>
                        <td>{{ $event->created_at->diffForHumans() }}</td>
                    </tr>
                @endforeach
                </table>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="font-semibold mb-3">Recent Audit Log</h3>
            <ul class="text-sm space-y-1">
                @foreach ($recentAudit as $log)
                    <li class="flex justify-between border-b pb-1">
                        <span class="font-mono">{{ $log->action }}</span>
                        <span class="text-gray-500">{{ $log->created_at->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</x-app-layout>
