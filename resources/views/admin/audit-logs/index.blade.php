<x-admin-shell :title="__('Audit Logs')" :subtitle="__('Every recorded action. Sensitive metadata is redacted before display.')">
    <x-admin-filters :submit="route('admin.audit-logs.index')" :columns="4">
        <div>
            <x-input-label for="search" :value="__('Search')" />
            <x-text-input id="search" name="search" class="mt-1 block w-full"
                          :value="$filters['search'] ?? ''" :placeholder="__('Action, resource or id')" />
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
            <x-input-label for="user_id" :value="__('User')" />
            <select id="user_id" name="user_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('Everyone') }}</option>
                @foreach ($users as $adminUser)
                    <option value="{{ $adminUser->id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $adminUser->id)>
                        {{ $adminUser->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="action_group" :value="__('Area')" />
            <select id="action_group" name="action_group" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('All areas') }}</option>
                @foreach ($groups as $group)
                    <option value="{{ $group }}" @selected(($filters['action_group'] ?? '') === $group)>{{ $group }}</option>
                @endforeach
            </select>
        </div>
    </x-admin-filters>

    <x-admin-table :paginator="$logs" :empty="__('No audit entries found.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('When') }}</th>
                <th class="px-4 py-3">{{ __('User') }}</th>
                <th class="px-4 py-3">{{ __('Action') }}</th>
                <th class="px-4 py-3">{{ __('Resource') }}</th>
                <th class="px-4 py-3">{{ __('IP') }}</th>
                <th class="px-4 py-3">{{ __('Context') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($logs as $log)
                <tr>
                    <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ $log->created_at?->format('d M Y H:i') }}</td>
                    <td class="px-4 py-3">{{ $log->user?->name ?? __('System') }}</td>
                    <td class="px-4 py-3">
                        <span class="whitespace-nowrap rounded-full bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-700">{{ $log->action }}</span>
                    </td>
                    <td class="px-4 py-3 text-gray-600">
                        {{ $log->resource_type ?? '—' }}
                        @if ($log->resource_id)<span class="text-xs text-gray-400">#{{ $log->resource_id }}</span>@endif
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500">{{ $log->ip_address ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if ($log->safe_metadata === [])
                            <span class="text-xs text-gray-400">—</span>
                        @else
                            <details>
                                <summary class="cursor-pointer text-xs text-indigo-600">{{ __('View') }}</summary>
                                <pre class="mt-1 max-w-xs overflow-x-auto rounded bg-gray-50 p-2 text-xs">{{ json_encode($log->safe_metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-gray-500">{{ __('No audit entries found.') }}</td></tr>
            @endforelse
        </tbody>
    </x-admin-table>
</x-admin-shell>