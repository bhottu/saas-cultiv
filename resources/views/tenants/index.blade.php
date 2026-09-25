<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Your Workspaces') }}</h2>
    </x-slot>

    <div class="py-12 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @php $workspaceLimitReached = $workspaceLimit !== null && $ownedCount >= $workspaceLimit; @endphp
        @if ($workspaceLimitReached)
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                <strong>Workspace limit reached.</strong>
                Your current plan includes {{ $workspaceLimit }} workspace. Existing workspaces are preserved.
                <a href="{{ route('billing.index') }}" class="ml-1 font-semibold underline">View Plans</a>
            </div>
        @endif

        @unless ($workspaceLimitReached)
        <form method="POST" action="{{ route('tenants.store') }}" class="bg-white shadow rounded-lg p-6 flex gap-3">
            @csrf
            <input name="name" required maxlength="100" placeholder="New workspace name"
                   class="flex-1 border-gray-300 rounded-lg shadow-sm">
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Create workspace</button>
        </form>
        @endunless

        <div class="bg-white shadow rounded-lg divide-y">
            @forelse ($tenants as $tenant)
                <div class="p-4 flex items-center justify-between">
                    <div>
                        <div class="font-semibold">{{ $tenant->name }}</div>
                        <div class="text-xs text-gray-500">Owner: {{ $tenant->owner->name }}</div>
                    </div>
                    <form method="POST" action="{{ route('tenants.switch', $tenant) }}">
                        @csrf
                        <button class="px-3 py-1.5 bg-indigo-600 text-white rounded text-sm">Open</button>
                    </form>
                </div>
            @empty
                <div class="p-6 text-gray-500">No workspaces yet — create one above.</div>
            @endforelse
        </div>
    </div>
</x-app-layout>
