@props(['paginator' => null, 'empty' => 'No records found.'])

{{--
    Table frame with horizontal scroll on narrow screens. The wrapper keeps the
    16–20px mobile gutter from the app layout, so a wide table scrolls inside its
    card instead of forcing the whole page to overflow.
--}}
<div class="admin-card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="admin-table min-w-full divide-y divide-gray-200 text-sm">
            {{ $slot }}
        </table>
    </div>
</div>

@if ($paginator?->hasPages() || ($paginator?->total() ?? 0) > 0)
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-gray-500">
        {{ $paginator?->total() ?? 0 }} {{ __('records') }}
        @if ($paginator?->hasPages()) · {{ $paginator->links() }} @endif
    </div>
@else
    <p class="admin-card p-6 text-sm text-gray-500">{{ $empty }}</p>
@endif
