<x-admin-shell :title="__('Users')" :subtitle="__('Every account registered on the platform.')">
    <x-admin-filters :submit="route('admin.users.index')" :columns="3">
        <div>
            <x-input-label for="search" :value="__('Search')" />
            <x-text-input id="search" name="search" class="mt-1 block w-full"
                          :value="$filters['search'] ?? ''" :placeholder="__('Name or email')" />
        </div>
        <div class="flex flex-wrap items-end gap-4">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="verified" value="1" class="rounded border-gray-300 text-indigo-600"
                       @checked(! empty($filters['verified']))> {{ __('Verified') }}
            </label>
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="unverified" value="1" class="rounded border-gray-300 text-indigo-600"
                       @checked(! empty($filters['unverified']))> {{ __('Unverified') }}
            </label>
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="platform_admin" value="1" class="rounded border-gray-300 text-indigo-600"
                       @checked(! empty($filters['platform_admin']))> {{ __('Platform admins') }}
            </label>
        </div>
        <div>
            <x-input-label for="workspace_id" :value="__('Workspace')" />
            <select id="workspace_id" name="workspace_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('All workspaces') }}</option>
                @foreach ($workspaces as $workspace)
                    <option value="{{ $workspace->id }}" @selected((string) ($filters['workspace_id'] ?? '') === (string) $workspace->id)>
                        {{ $workspace->name }}
                    </option>
                @endforeach
            </select>
        </div>
    </x-admin-filters>

    <x-admin-table :paginator="$users" :empty="__('No users found.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('Name') }}</th>
                <th class="px-4 py-3">{{ __('Email') }}</th>
                <th class="px-4 py-3">{{ __('Verified') }}</th>
                <th class="px-4 py-3">{{ __('Workspaces') }}</th>
                <th class="px-4 py-3">{{ __('Platform admin') }}</th>
                <th class="px-4 py-3">{{ __('Joined') }}</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($users as $user)
                <tr>
                    <td class="px-4 py-3 font-medium text-gray-900">{{ $user->name }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $user->email }}</td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2 py-0.5 text-xs {{ $user->email_verified_at ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">
                            {{ $user->email_verified_at ? __('Yes') : __('No') }}
                        </span>
                    </td>
                    <td class="px-4 py-3">{{ $user->tenants_count }}</td>
                    <td class="px-4 py-3">{{ $user->is_platform_admin ? __('Yes') : '—' }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ $user->created_at?->format('d M Y') }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.users.show', $user) }}" class="text-indigo-600 hover:underline">{{ __('View') }}</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-gray-500">{{ __('No users found.') }}</td></tr>
            @endforelse
        </tbody>
    </x-admin-table>
</x-admin-shell>