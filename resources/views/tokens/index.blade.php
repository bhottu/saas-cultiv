<x-app-layout>
    <x-slot name="header">
        {{-- Feature name: this screen is "API Access"; the token is only the credential
             it hands out. --}}
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('API Access') }}</h2>
    </x-slot>

    <div class="py-12 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
        {{-- One-time plaintext display: shown on create and on rotate, never again. --}}
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

        @if (! $apiEnabled)
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-5 text-sm text-amber-900">
                <strong>{{ __('API Access is available on the :plans plans.', ['plans' => $featurePlans]) }}</strong>
                {{ __('Upgrade your plan to create and use API tokens.') }}
                <a href="{{ route('billing.index') }}" class="ml-1 font-semibold underline">{{ __('View Plans') }}</a>
            </div>
        @endif

        @if ($apiEnabled)
            {{-- Plan & limits: exactly what the server enforces, read from the plan's own
                 entitlements so this screen can never advertise a number the server does
                 not apply. --}}
            <div class="bg-white shadow rounded-lg p-6">
                <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-3">{{ __('Plan and limits') }}</div>
                <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">{{ __('Plan') }}</dt>
                        <dd class="font-semibold text-gray-900">{{ $planName }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Rate limit') }}</dt>
                        <dd class="font-semibold text-gray-900">{{ number_format($rateLimit ?? 0) }} {{ __('requests/minute') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Monthly quota') }}</dt>
                        <dd class="font-semibold text-gray-900">{{ $quota === null ? __('Unlimited') : number_format($quota) }} {{ __('requests/month') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Used this month') }}</dt>
                        <dd class="font-semibold text-gray-900">{{ number_format($quotaUsed) }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Create --}}
            <form method="POST" action="{{ route('tokens.store') }}" class="bg-white shadow rounded-lg p-6 space-y-4">
                @csrf
                <div>
                    <label for="token-name" class="block text-sm font-medium text-gray-700 mb-1">{{ __('Token name') }}</label>
                    <input id="token-name" name="name" required maxlength="100"
                           placeholder="{{ __('Token name (e.g. CLI, Mobile app)') }}"
                           class="w-full border-gray-300 rounded-lg shadow-sm text-sm"
                           value="{{ old('name') }}">
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Permissions: only scopes the platform currently offers render as usable
                     checkboxes — /admin/api decides the list, this form picks from it. --}}
                <fieldset>
                    <legend class="text-sm font-medium text-gray-700 mb-2">{{ __('Permissions') }}</legend>
                    <div class="divide-y divide-gray-100 border border-gray-200 rounded-lg">
                        @foreach ($scopeMatrix as $row)
                            @php
                                $readScope = $row['resource'].':read';
                                $writeScope = $row['resource'].':write';
                                $canRead = in_array($readScope, $availableScopes, true);
                                $canWrite = in_array($writeScope, $availableScopes, true);
                            @endphp
                            <div class="flex items-center justify-between px-4 py-2.5 text-sm">
                                <span class="text-gray-800">{{ __($row['label']) }}</span>
                                <span class="flex gap-5">
                                    <label class="inline-flex items-center gap-2 {{ $canRead ? 'text-gray-700' : 'text-gray-300' }}">
                                        <input type="checkbox" name="scopes[]" value="{{ $readScope }}"
                                               @disabled(! $canRead)
                                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        {{ __('Read') }}
                                    </label>
                                    <label class="inline-flex items-center gap-2 {{ $canWrite ? 'text-gray-700' : 'text-gray-300' }}">
                                        <input type="checkbox" name="scopes[]" value="{{ $writeScope }}"
                                               @disabled(! $canWrite)
                                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        {{ __('Write') }}
                                    </label>
                                </span>
                            </div>
                        @endforeach
                    </div>
                    @error('scopes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </fieldset>

                <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">{{ __('Create token') }}</button>
            </form>

            {{-- Usage hint. The workspace resolves FROM THE TOKEN — there is no
                 X-Tenant-Id header to send, and none would be honoured. --}}
            <div class="bg-gray-50 border rounded-lg p-4 text-xs text-gray-600">
                <p class="font-semibold mb-1">{{ __('Usage') }}</p>
                <code class="block">curl -H "Authorization: Bearer &lt;token&gt;" {{ config('app.url') }}/api/v1/products</code>
                <p class="mt-1">{{ __('The workspace is taken from the token itself — no extra header needed.') }}</p>
            </div>

            {{-- Token list --}}
            <div class="bg-white shadow rounded-lg divide-y">
                <div class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('Tokens') }}</div>
                @forelse ($tokens as $token)
                    <div class="p-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-semibold">{{ $token->name }}</div>
                            <div class="mt-1 flex flex-wrap gap-1">
                                @if (in_array('*', (array) $token->abilities, true))
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">{{ __('Legacy full-access token') }}</span>
                                @else
                                    @foreach ((array) $token->abilities as $ability)
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-700">{{ $ability }}</span>
                                    @endforeach
                                @endif
                            </div>
                            <div class="mt-1 text-xs text-gray-500">
                                {{ __('created') }} {{ $token->created_at->format('d M Y') }}
                                @if ($token->last_used_at) · {{ __('last used') }} {{ $token->last_used_at->diffForHumans() }} @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <form method="POST" action="{{ route('tokens.rotate', $token->id) }}"
                                  onsubmit="return confirm('{{ __('Rotate this token? The current secret stops working immediately.') }}')">
                                @csrf
                                <button class="text-indigo-600 text-sm underline">{{ __('Rotate') }}</button>
                            </form>
                            <form method="POST" action="{{ route('tokens.destroy', $token->id) }}"
                                  onsubmit="return confirm('{{ __('Revoke this token? Clients using it will get 401.') }}')">
                                @csrf
                                @method('DELETE')
                                <button class="text-red-600 text-sm underline">{{ __('Revoke') }}</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <div class="p-6 text-gray-500">{{ __('No tokens yet.') }}</div>
                @endforelse
            </div>

            {{-- Recent API activity: this workspace's own requests only. --}}
            <div class="bg-white shadow rounded-lg divide-y">
                <div class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('Recent API activity') }}</div>
                @if ($recentUsage->isEmpty())
                    <div class="p-6 text-gray-500">{{ __('No API activity yet.') }}</div>
                @else
                    <table class="min-w-full text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($recentUsage as $entry)
                                <tr class="text-xs">
                                    <td class="px-4 py-2 font-mono text-gray-700">{{ $entry->method }}</td>
                                    <td class="px-4 py-2 font-mono text-gray-700">{{ $entry->path }}</td>
                                    <td class="px-4 py-2 {{ $entry->status_code < 400 ? 'text-green-600' : 'text-red-600' }}">{{ $entry->status_code }}</td>
                                    <td class="px-4 py-2 text-gray-400">{{ $entry->duration_ms }} ms</td>
                                    <td class="px-4 py-2 text-gray-400 text-right pr-5">{{ $entry->created_at->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>

