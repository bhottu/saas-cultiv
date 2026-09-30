<x-admin-shell :title="$user->name" :subtitle="$user->email">
    <div class="rounded-lg bg-white p-6 shadow">
        <h3 class="mb-4 font-semibold text-gray-900">{{ __('Account') }}</h3>
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Verified') }}</dt>
                <dd class="mt-0.5 text-sm text-gray-900">{{ $user->email_verified_at ? __('Yes') : __('No') }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Platform admin') }}</dt>
                <dd class="mt-0.5 text-sm text-gray-900">{{ $user->is_platform_admin ? __('Yes') : __('No') }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Joined') }}</dt>
                <dd class="mt-0.5 text-sm text-gray-900">{{ $user->created_at?->format('d M Y') }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Workspaces') }}</dt>
                <dd class="mt-0.5 text-sm text-gray-900">{{ $user->tenants_count }}</dd>
            </div>
        </dl>
    </div>

    <x-admin-table :empty="__('This user is not a member of any workspace.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('Workspace') }}</th>
                <th class="px-4 py-3">{{ __('Role') }}</th>
                <th class="px-4 py-3">{{ __('Status') }}</th>
                <th class="px-4 py-3">{{ __('Plan') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach ($user->tenants as $tenant)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.workspaces.show', $tenant) }}" class="text-indigo-600 hover:underline">{{ $tenant->name }}</a>
                    </td>
                    <td class="px-4 py-3">{{ $tenant->pivot->role }}</td>
                    <td class="px-4 py-3">{{ $tenant->pivot->status }}</td>
                    <td class="px-4 py-3">{{ $tenant->activeSubscription?->plan?->name ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </x-admin-table>

    <x-admin-table :empty="__('No subscriptions.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('Plan') }}</th>
                <th class="px-4 py-3">{{ __('Status') }}</th>
                <th class="px-4 py-3">{{ __('Started') }}</th>
                <th class="px-4 py-3">{{ __('Renews') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($subscriptions as $subscription)
                <tr>
                    <td class="px-4 py-3">{{ $subscription->plan?->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ ucfirst($subscription->status) }}</td>
                    <td class="px-4 py-3">{{ optional($subscription->current_period_start)->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-3">{{ optional($subscription->current_period_end)->format('d M Y') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-6 text-gray-500">{{ __('No subscriptions.') }}</td></tr>
            @endforelse
        </tbody>
    </x-admin-table>

    <div class="rounded-lg bg-white p-6 shadow">
        <h3 class="mb-3 font-semibold text-gray-900">{{ __('Recent activity') }}</h3>
        <ul class="space-y-1 text-sm">
            @forelse ($activity as $log)
                <li class="flex flex-wrap justify-between gap-2 border-b pb-1">
                    <span class="font-mono">{{ $log->action }}</span>
                    <span class="text-gray-500">{{ $log->created_at?->diffForHumans() }}</span>
                </li>
            @empty
                <li class="text-gray-500">{{ __('No activity recorded.') }}</li>
            @endforelse
        </ul>
    </div>
</x-admin-shell>