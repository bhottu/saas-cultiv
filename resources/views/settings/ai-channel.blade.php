<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('AI Assistant Telegram') }}</h2>
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        @if (session('status'))
            <div class="rounded-lg border px-4 py-3 text-sm {{ (session('status.type') ?? 'success') === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">
                {{ session('status.message') }}
            </div>
        @endif

        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
            <h3 class="text-base font-semibold text-gray-900">{{ __('Telegram account') }}</h3>
            <p class="mt-2 text-sm text-gray-600">{{ __('Link one Telegram account to your user and the :workspace workspace. Only your existing workspace permissions are available to the assistant.', ['workspace' => $tenant->name]) }}</p>

            @if ($link)
                <div class="mt-5 flex flex-wrap items-center justify-between gap-4 rounded-lg bg-emerald-50 p-4">
                    <div>
                        <p class="text-sm font-medium text-emerald-900">{{ __('Telegram is linked.') }}</p>
                        <p class="mt-1 text-xs text-emerald-800">{{ __('Linked on :date', ['date' => $link->linked_at?->format('Y-m-d H:i')]) }}</p>
                    </div>
                    <form method="POST" action="{{ route('ai.channel.unlink') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">{{ __('Unlink Telegram') }}</button>
                    </form>
                </div>
            @else
                @if (! $telegramConfigured)
                    <p class="mt-5 rounded-lg bg-amber-50 p-4 text-sm text-amber-800">{{ __('Telegram is not configured for this platform yet.') }}</p>
                @elseif (session('telegram_link_code'))
                    <div class="mt-5 rounded-lg border border-indigo-200 bg-indigo-50 p-4" role="status">
                        <p class="text-sm font-medium text-indigo-900">{{ __('This one-time code expires in 10 minutes. Send this command to your AI Assistant Telegram bot:') }}</p>
                        <code class="mt-3 block select-all rounded bg-white px-3 py-2 font-mono text-lg tracking-widest text-indigo-900">/link {{ session('telegram_link_code') }}</code>
                        <p class="mt-2 text-xs text-indigo-800">{{ __('The code is shown only once. Do not share it with anyone.') }}</p>
                    </div>
                @else
                    <form method="POST" action="{{ route('ai.channel.link-code') }}" class="mt-5">
                        @csrf
                        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            {{ __('Generate one-time link code') }}
                        </button>
                    </form>
                @endif
            @endif
        </section>

        <a href="{{ route('settings.index') }}" class="inline-flex text-sm font-medium text-indigo-600 hover:underline">{{ __('Back to settings') }}</a>
    </div>
</x-app-layout>
