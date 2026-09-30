{{-- Same table as simple-table, with money columns right-aligned for readability. --}}
@props(['title', 'headers', 'rows', 'columns', 'empty' => null])

<div class="overflow-x-auto rounded-lg bg-white shadow">
    <div class="border-b border-gray-100 px-5 py-4 text-sm font-semibold text-gray-800">{{ $title }}</div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    @foreach ($headers as $index => $header)
                        <th class="whitespace-nowrap px-4 py-3 {{ $index > 0 ? 'text-right' : '' }}">{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($columns as $index => $column)
                            <td class="whitespace-nowrap px-4 py-3 {{ $index > 0 ? 'text-right tabular-nums' : 'text-gray-800' }}">
                                {{ $column($row) }}
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($headers) }}" class="px-4 py-6 text-gray-500">
                            {{ $empty ?? __('No data for this period.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>