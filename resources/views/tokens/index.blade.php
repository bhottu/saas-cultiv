<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('API Tokens') }}</h2>
    </x-slot>

    <div class="py-12 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
        {{-- One-time plaintext display --}}
        @if (session('plainTextToken'))
            <div class="bg-green-50 border border-green-300 rounded-lg p-5">
                <h3 class="font-semibold text-green-800 mb-1">Token created — copy it now</h3>
                <p class="text-xs text-green-700 mb-2">This is the only time the full token is shown. Only a SHA-256 hash is stored server-side.</p>
                <code class="block bg-white border rounded p-3 text-xs break-all select-all">{{ session('plainTextToken') }}</code>
            </div>
        @endif

        @if (session('success'))
            <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('success') }}</div>
        @endif

        {{-- Create --}}
        @if ($apiEnabled)
        <form method="POST" action="{{ route('tokens.store') }}" class="bg-white shadow rounded-lg p-6 flex gap-3">
            @csrf
            <input name="name" required maxlength="100" placeholder="Token name (e.g. CLI, Mobile app)"
                   class="flex-1 border-gray-300 rounded-lg shadow-sm text-sm">
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">Create token</button>
        </form>
        @else
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-5 text-sm text-amber-900">
                <strong>API Access is available on the Business plan.</strong>
                Upgrade your plan to create and use API tokens.
                <a href="{{ route('billing.index') }}" class="ml-1 font-semibold underline">View Plans</a>
            </div>
        @endif

        @if ($apiEnabled)
        {{-- Usage hint --}}
        <div class="bg-gray-50 border rounded-lg p-4 text-xs text-gray-600">
            <p class="font-semibold mb-1">Usage</p>
            <code class="block">curl -H "Authorization: Bearer &lt;token&gt;" -H "X-Tenant-Id: &lt;tenant&gt;" {{ config('app.url') }}/api/v1/usage</code>
            <p class="mt-1">Rate limit: 60 requests/minute. Quotas apply per plan (see <a class="underline" href="{{ route('billing.index') }}">billing</a>).</p>
        </div>
        @endif

        {{-- List --}}
        <div class="bg-white shadow rounded-lg divide-y">
            @forelse ($tokens as $token)
                <div class="p-4 flex items-center justify-between">
                    <div>
                        <div class="font-semibold">{{ $token->name }}</div>
                        <div class="text-xs text-gray-500">
                            created {{ $token->created_at->format('d M Y') }}
                            @if ($token->last_used_at) · last used {{ $token->last_used_at->diffForHumans() }} @endif
                        </div>
                    </div>
                    <form method="POST" action="{{ route('tokens.destroy', $token->id) }}"
                          onsubmit="return confirm('Revoke this token? Clients using it will get 401.')">
                        @csrf
                        @method('DELETE')
                        <button class="text-red-600 text-sm underline">Revoke</button>
                    </form>
                </div>
            @empty
                <div class="p-6 text-gray-500">No tokens yet.</div>
            @endforelse
        </div>
    </div>
</x-app-layout>
