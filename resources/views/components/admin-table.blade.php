@props(['paginator' => null, 'empty' => 'No records found.'])

{{--
    Table frame with horizontal scroll on narrow screens. The wrapper keeps the
    16–20px mobile gutter from the app layout, so a wide table scrolls inside its
    card instead of forcing the whole page to overflow.
--}}
<div class="rounded-lg bg-white shadow">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            {{ $slot }}
        </table>
    </div>
</div>

@if ($paginator?->hasPages() || ($paginator?->total() ?? 0) > 0)
    <div class="text-sm text-gray-500">
        {{ $paginator?->total() ?? 0 }} {{ __('records') }}
        @if ($paginator?->hasPages()) · {{ $paginator->links() }} @endif
    </div>
@else
    <p class="rounded-lg bg-white p-6 text-sm text-gray-500 shadow">{{ $empty }}</p>
@endif
