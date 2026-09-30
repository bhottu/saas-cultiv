<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ $entry->action }}</h2>
                <p class="truncate text-sm text-gray-500">
                    {{ $entry->created_at?->format('d M Y H:i:s') }} · {{ $entry->user?->name ?? __('System') }}
                </p>
            </div>
            <a href="{{ route('audit-logs.index') }}" class="text-sm text-gray-600 underline">{{ __('Back to audit log') }}</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-6 py-12 sm:px-6 lg:px-8">
        <div class="rounded-lg bg-white p-6 shadow">
            <h3 class="mb-4 font-semibold text-gray-900">{{ __('Event') }}</h3>
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Action') }}</dt>
                    <dd class="mt-0.5 font-medium text-gray-900">{{ $entry->action }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('User') }}</dt>
                    <dd class="mt-0.5 text-gray-900">
                        {{ $entry->user?->name ?? __('System') }}
                        @if ($entry->user?->email)
                            <span class="block text-xs text-gray-500">{{ $entry->user->email }}</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Resource') }}</dt>
                    <dd class="mt-0.5 text-gray-900">
                        {{ $entry->resource_type ?? '—' }}
                        @if ($entry->resource_id) <span class="text-gray-500">#{{ $entry->resource_id }}</span> @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('When') }}</dt>
                    <dd class="mt-0.5 text-gray-900">{{ $entry->created_at?->format('d M Y H:i:s') }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('IP address') }}</dt>
                    <dd class="mt-0.5 text-gray-900">{{ $entry->ip_address ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Browser') }}</dt>
                    <dd class="mt-0.5 break-all text-xs text-gray-600">{{ $entry->user_agent ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-lg bg-white p-6 shadow">
            <h3 class="mb-1 font-semibold text-gray-900">{{ __('Context') }}</h3>
            <p class="mb-4 text-sm text-gray-500">{{ __('Values recorded with this event. Sensitive keys are never displayed.') }}</p>

            @if ($metadata === [])
                <p class="text-sm text-gray-500">{{ __('No additional context was recorded.') }}</p>
            @else
                <dl class="divide-y divide-gray-100 text-sm">
                    @foreach ($metadata as $key => $value)
                        <div class="grid gap-1 py-2 sm:grid-cols-3">
                            <dt class="font-medium text-gray-700">{{ $key }}</dt>
                            <dd class="text-gray-600 sm:col-span-2">
                                @if (is_array($value))
                                    <pre class="overflow-x-auto rounded bg-gray-50 p-2 text-xs">{{ json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                @else
                                    {{ is_bool($value) ? ($value ? 'true' : 'false') : $value }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </div>
    </div>
</x-app-layout>