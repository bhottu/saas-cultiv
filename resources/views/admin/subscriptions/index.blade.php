<x-admin-shell :title="__('Subscriptions')" :subtitle="__('Every subscription on the platform. Read-only.')">
    <x-admin-filters :submit="route('admin.subscriptions.index')" :columns="4">
        <div>
            <x-input-label for="tenant_id" :value="__('Workspace')" />
            <select id="tenant_id" name="tenant_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('All workspaces') }}</option>
                @foreach ($workspaces as $workspace)
                    <option value="{{ $workspace->id }}" @selected((string) ($filters['tenant_id'] ?? '') === (string) $workspace->id)>
                        {{ $workspace->name }}@if ($workspace->trashed()) ({{ __('deleted') }})@endif
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="plan_id" :value="__('Plan')" />
            <select id="plan_id" name="plan_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('All plans') }}</option>
                @foreach ($plans as $plan)
                    <option value="{{ $plan->id }}" @selected((string) ($filters['plan_id'] ?? '') === (string) $plan->id)>{{ $plan->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="status" :value="__('Status')" />
            <select id="status" name="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="from" :value="__('From')" />
            <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$filters['from'] ?? ''" />
        </div>
        <div>
            <x-input-label for="to" :value="__('To')" />
            <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$filters['to'] ?? ''" />
        </div>
    </x-admin-filters>

    <x-admin-table :paginator="$subscriptions" :empty="__('No subscriptions found.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('Workspace') }}</th>
                <th class="px-4 py-3">{{ __('Plan') }}</th>
                <th class="px-4 py-3">{{ __('Status') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
                <th class="px-4 py-3">{{ __('Started') }}</th>
                <th class="px-4 py-3">{{ __('Renews') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($subscriptions as $subscription)
                <tr>
                    <td class="px-4 py-3">
                        {{ $subscription->tenant?->name ?? '—' }}
                        @if ($subscription->tenant?->trashed())
                            <span class="ml-1 rounded-full bg-red-100 px-1.5 py-0.5 text-xs text-red-700">{{ __('deleted') }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $subscription->plan?->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ ucfirst(str_replace('_', ' ', $subscription->status)) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right">{{ \App\Services\Money::formatRupiah($subscription->amount ?? 0) }}</td>
                    <td class="px-4 py-3">{{ optional($subscription->current_period_start)->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-3">{{ optional($subscription->current_period_end)->format('d M Y') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-gray-500">{{ __('No subscriptions found.') }}</td></tr>
            @endforelse
        </tbody>
    </x-admin-table>
</x-admin-shell>