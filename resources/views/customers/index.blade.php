<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ __('Customers') }}</h2>
                <p class="truncate text-sm text-gray-500">{{ __('Buyers of this workspace — a sale can also be a walk-in.') }}</p>
            </div>

            <a href="{{ route('customers.create') }}"
               class="inline-flex shrink-0 items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                {{ __('Add customer') }}
            </a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        @if (session('status'))
            @php $status = session('status'); @endphp
            <div class="rounded-lg p-3 {{ ($status['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                {{ $status['message'] ?? '' }}
            </div>
        @endif

        <form method="GET" action="{{ route('customers.index') }}" class="grid gap-4 rounded-lg bg-white p-6 shadow md:grid-cols-4">
            <div class="md:col-span-2">
                <x-input-label for="search" :value="__('Search')" />
                <x-text-input id="search" name="search" type="text" class="mt-1 block w-full"
                              :value="$search ?? ''"
                              placeholder="{{ __('Name, phone or email') }}" />
            </div>

            <div class="flex items-end gap-6 md:col-span-2">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="active_only" value="1" class="rounded border-gray-300 text-indigo-600 shadow-sm"
                           @checked($active_only ?? false)>
                    {{ __('Active only') }}
                </label>

                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="show_inactive" value="1" class="rounded border-gray-300 text-indigo-600 shadow-sm"
                           @checked(request()->boolean('show_inactive'))>
                    {{ __('Inactive only') }}
                </label>
            </div>

            <div class="flex items-center gap-3 md:col-span-4">
                <button class="rounded-lg bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-700">{{ __('Filter') }}</button>
                <a href="{{ route('customers.index') }}" class="text-sm text-gray-500 underline">{{ __('Reset') }}</a>
            </div>
        </form>

        <div class="overflow-x-auto rounded-lg bg-white shadow">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-4 py-3">{{ __('Customer') }}</th>
                        <th class="px-4 py-3">{{ __('Phone') }}</th>
                        <th class="px-4 py-3">{{ __('Email') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Credit limit') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($customers as $customer)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('customers.show', $customer) }}" class="font-medium text-indigo-700 hover:underline">
                                    {{ $customer->name }}
                                </a>
                                @if ($customer->address)
                                    <div class="text-xs text-gray-500">{{ $customer->address }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $customer->phone ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $customer->email ?: '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ \App\Services\Money::format($customer->credit_limit) }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs {{ $customer->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $customer->is_active ? __('Active') : __('Inactive') }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-3">
                                    <a href="{{ route('customers.show', $customer) }}" class="text-indigo-600 hover:underline">{{ __('View') }}</a>
                                    <a href="{{ route('customers.edit', $customer) }}" class="text-gray-600 hover:underline">{{ __('Edit') }}</a>
                                    <form method="POST" action="{{ route('customers.destroy', $customer) }}"
                                          onsubmit="return confirm('Delete this customer?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-red-600 hover:underline">{{ __('Delete') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-gray-500">
                                {{ __('No customers yet.') }}
                                <a href="{{ route('customers.create') }}" class="text-indigo-600 underline">{{ __('Add the first one') }}</a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $customers->links() }}</div>
    </div>
</x-app-layout>