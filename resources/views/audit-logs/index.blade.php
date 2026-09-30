<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ __('Audit Log') }}</h2>
                <p class="truncate text-sm text-gray-500">{{ __('Every recorded action inside this workspace.') }}</p>
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
            <x-kpi-card :label="__('Total entries')" :value="number_format($summary['total'])" />
            <x-kpi-card :label="__('Today')" :value="number_format($summary['today'])" />
            <x-kpi-card :label="__('Deletions')" :value="number_format($summary['deletions'])"
                        :tone="$summary['deletions'] > 0 ? 'negative' : 'default'" />
            <x-kpi-card :label="__('People involved')" :value="$summary['people']" />
        </div>

        <form method="GET" action="{{ route('audit-logs.index') }}"
              class="bg-white shadow rounded-lg p-5 grid gap-4 md:grid-cols-3 lg:grid-cols-6">
            <div>
                <x-input-label for="search" :value="__('Search')" />
                <x-text-input id="search" name="search" type="text" class="mt-1 block w-full"
                              :value="$filters['search']" :placeholder="__('action, type, id')" />
            </div>

            <div>
                <x-input-label for="action_group" :value="__('Area')" />
                <select id="action_group" name="action_group"
                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    <option value="">{{ __('All areas') }}</option>
                    @foreach ($groups as $value => $label)
                        <option value="{{ $value }}" @selected($filters['action_group'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="action" :value="__('Action')" />
                <select id="action" name="action"
                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    <option value="">{{ __('All actions') }}</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected($filters['action'] === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="user_id" :value="__('User')" />
                <select id="user_id" name="user_id"
                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    <option value="">{{ __('Everyone') }}</option>
                    @foreach ($users as $member)
                        <option value="{{ $member->id }}" @selected((int) $filters['user_id'] === (int) $member->id)>
                            {{ $member->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="from" :value="__('From')" />
                <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$filters['from']" />
            </div>

            <div>
                <x-input-label for="to" :value="__('To')" />
                <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$filters['to']" />
            </div>

            <div class="flex items-end gap-2 md:col-span-3 lg:col-span-6">
                <x-primary-button>{{ __('Filter') }}</x-primary-button>
                <a href="{{ route('audit-logs.index') }}"
                   class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
                    {{ __('Reset') }}
                </a>
            </div>
        </form>

        <div class="rounded-lg bg-white shadow">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3">{{ __('When') }}</th>
                            <th class="px-4 py-3">{{ __('User') }}</th>
                            <th class="px-4 py-3">{{ __('Action') }}</th>
                            <th class="px-4 py-3">{{ __('Resource') }}</th>
                            <th class="px-4 py-3">{{ __('IP') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($entries as $entry)
                            @php
                                $tone = str_ends_with($entry->action, '.deleted')
                                    ? 'bg-red-100 text-red-700'
                                    : 'bg-gray-100 text-gray-600';
                            @endphp
                            <tr data-audit-action="{{ $entry->action }}">
                                <td class="whitespace-nowrap px-4 py-3 text-gray-500">
                                    {{ $entry->created_at?->format('d M Y H:i') }}
                                </td>
                                <td class="px-4 py-3 text-gray-800">{{ $entry->user?->name ?? __('System') }}</td>
                                <td class="px-4 py-3">
                                    <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs {{ $tone }}">{{ $entry->action }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    {{ $entry->resource_type ?? '—' }}
                                    @if ($entry->resource_id)
                                        <span class="text-xs text-gray-400">#{{ $entry->resource_id }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500">{{ $entry->ip_address ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('audit-logs.show', $entry->id) }}" class="text-indigo-600 hover:underline">
                                        {{ __('Details') }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-gray-500">{{ __('No activity recorded yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>{{ $entries->links() }}</div>
    </div>
</x-app-layout>
