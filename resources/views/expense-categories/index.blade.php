<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Expense Categories') }}</h2>
            <div class="flex items-center gap-3">
                <a href="{{ route('expenses.index') }}" class="text-sm underline">{{ __('Back to expenses') }}</a>
                @if ($canCreate)
                    <a href="{{ route('expense-categories.create') }}"
                       class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        {{ __('Add category') }}
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-6 py-12 sm:px-6 lg:px-8">
        {{-- Reached from the expense form's category dropdown, so state the link back
             explicitly rather than leaving that entry point as a dead end. --}}
        <p class="text-sm text-gray-600">{{ __('Categories here classify spending. Product categories are managed separately.') }}</p>

        @if (session('status'))
            @php($status = session('status'))
            <div role="{{ ($status['type'] ?? 'success') === 'error' ? 'alert' : 'status' }}"
                 class="rounded-lg p-3 {{ ($status['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                {{ $status['message'] ?? '' }}
            </div>
        @endif

        {{-- Listing: identical wrapper, table, head row and body classes to sales/index. --}}
        <div class="overflow-x-auto rounded-lg bg-white shadow">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-4 py-3">{{ __('Category') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Expenses') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($categories as $category)
                        <tr>
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $category->name }}</td>
                            <td class="px-4 py-3 text-right text-gray-600">{{ $category->expenses_count }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs {{ $category->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $category->is_active ? __('Active') : __('Inactive') }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-3">
                                    @if ($canUpdate)
                                        <a href="{{ route('expense-categories.edit', $category) }}" class="text-indigo-600 hover:underline">{{ __('Edit') }}</a>
                                    @endif
                                    @if ($canDelete)
                                        <form method="POST" action="{{ route('expense-categories.destroy', $category) }}"
                                              onsubmit="return confirm('{{ __('Delete this category?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-red-600 hover:underline">{{ __('Delete') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-gray-500">
                                {{ __('No expense categories yet.') }}
                                @if ($canCreate)
                                    <a href="{{ route('expense-categories.create') }}" class="text-indigo-600 underline">{{ __('Add the first one') }}</a>.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>