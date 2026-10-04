<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Expenses') }}</h2>
            <div class="flex items-center gap-3">
                <a href="{{ route('expense-categories.index') }}" class="text-sm underline">{{ __('Manage categories') }}</a>
                <a href="{{ route('expenses.create') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white">{{ __('Add expense') }}</a>
            </div>
        </div>
    </x-slot>
    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8">
        @if (session('status'))
            @php $status = session('status'); @endphp
            <div class="rounded-lg p-3 {{ ($status['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                {{ $status['message'] ?? '' }}
            </div>
        @endif

        {{-- Filter row lifted from sales/index. The Filter control was a bare
             <x-primary-button> sitting as an item in a `sm:grid-cols-3` grid, so it
             stretched across a whole column — that is why the button was oversized.
             Its own flex row sizes it to the word instead, and Reset clears the dates. --}}
        <form method="GET" action="{{ route('expenses.index') }}" class="grid gap-4 rounded-lg bg-white p-6 shadow md:grid-cols-4">
            <div>
                <x-input-label for="from" :value="__('From date')" />
                <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$filters['from'] ?? ''" />
            </div>

            <div>
                <x-input-label for="to" :value="__('To date')" />
                <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$filters['to'] ?? ''" />
            </div>

            <div class="flex items-center gap-3 md:col-span-4">
                <button class="rounded-lg bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-700">{{ __('Filter') }}</button>
                <a href="{{ route('expenses.index') }}" class="text-sm text-gray-500 underline">{{ __('Reset') }}</a>
            </div>
        </form>
        <div class="grid gap-4 sm:grid-cols-3"><div class="rounded-lg bg-white p-5 shadow"><div class="text-sm text-gray-500">This filtered list</div><div class="text-2xl font-semibold">{{ $expenses->total() }}</div></div><div class="rounded-lg bg-white p-5 shadow"><div class="text-sm text-gray-500">Total expenses</div><div class="text-2xl font-semibold">{{ \App\Services\Money::format($expenses->sum('amount')) }}</div></div></div>

        {{-- Listing: identical wrapper, table, head row and body classes to sales/index. --}}
        <div class="overflow-x-auto rounded-lg bg-white shadow">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-4 py-3">{{ __('Date') }}</th>
                        <th class="px-4 py-3">{{ __('Description') }}</th>
                        <th class="px-4 py-3">{{ __('Category') }}</th>
                        <th class="px-4 py-3">{{ __('Method') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($expenses as $expense)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3">{{ $expense->expense_date?->format('d M Y') }}</td>
                            <td class="px-4 py-3">
                                <a class="font-medium text-indigo-700 hover:underline" href="{{ route('expenses.show', $expense) }}">{{ $expense->description }}</a>
                            </td>
                            <td class="px-4 py-3">{{ $expense->category?->name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ str($expense->payment_method)->headline() }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ \App\Services\Money::format($expense->amount) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-3">
                                    <a href="{{ route('expenses.edit', $expense) }}" class="text-indigo-600 hover:underline">{{ __('Edit') }}</a>
                                    <form method="POST" action="{{ route('expenses.destroy', $expense) }}"
                                          onsubmit="return confirm('{{ __('Delete this expense?') }}')">
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
                                {{ __('No expenses found.') }}
                                <a href="{{ route('expenses.create') }}" class="text-indigo-600 underline">{{ __('Record the first one') }}</a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $expenses->links() }}</div>
    </div>
</x-app-layout>