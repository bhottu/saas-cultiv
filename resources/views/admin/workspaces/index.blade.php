<x-admin-shell :title="__('Workspaces')" :subtitle="__('Every workspace on the platform, including soft-deleted ones.')">
    <x-admin-filters :submit="route('admin.workspaces.index')" :columns="3">
        <div>
            <x-input-label for="search" :value="__('Search')" />
            <x-text-input id="search" name="search" class="mt-1 block w-full"
                          :value="$filters['search'] ?? ''" :placeholder="__('Workspace name')" />
        </div>
        <div class="flex flex-wrap items-end gap-4">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="active" value="1" class="rounded border-gray-300 text-indigo-600"
                       @checked(! empty($filters['active']))> {{ __('Active only') }}
            </label>
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="deleted" value="1" class="rounded border-gray-300 text-indigo-600"
                       @checked(! empty($filters['deleted']))> {{ __('Soft-deleted only') }}
            </label>
        </div>
        <div></div>
    </x-admin-filters>

    <x-admin-table :paginator="$workspaces" :empty="__('No workspaces found.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('Workspace') }}</th>
                <th class="px-4 py-3">{{ __('Owner') }}</th>
                <th class="px-4 py-3">{{ __('Plan') }}</th>
                <th class="px-4 py-3">{{ __('Users') }}</th>
                <th class="px-4 py-3">{{ __('Products') }}</th>
                <th class="px-4 py-3">{{ __('Customers') }}</th>
                <th class="px-4 py-3">{{ __('Sales') }}</th>
                <th class="px-4 py-3">{{ __('Status') }}</th>
                <th class="px-4 py-3">{{ __('Created') }}</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($workspaces as $workspace)
                <tr>
                    <td class="px-4 py-3 font-medium text-gray-900">{{ $workspace->name }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $workspace->owner?->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $workspace->activeSubscription?->plan?->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $workspace->users_count }}</td>
                    <td class="px-4 py-3">{{ $workspace->products_count }}</td>
                    <td class="px-4 py-3">{{ $workspace->customers_count }}</td>
                    <td class="px-4 py-3">{{ $workspace->sales_count }}</td>
                    <td class="px-4 py-3">
                        @if ($workspace->trashed())
                            <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">{{ __('Soft deleted') }}</span>
                        @else
                            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700">{{ ucfirst($workspace->status) }}</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ $workspace->created_at?->format('d M Y') }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.workspaces.show', $workspace->id) }}" class="text-indigo-600 hover:underline">{{ __('View') }}</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-4 py-6 text-gray-500">{{ __('No workspaces found.') }}</td></tr>
            @endforelse
        </tbody>
    </x-admin-table>
</x-admin-shell>