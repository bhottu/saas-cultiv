<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Settings') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- Tabs. Plain links rather than Alpine so each section is bookmarkable,
                 survives a reload, and works before/without JavaScript. The active tab
                 comes from the ?tab= query the controller already validated. --}}
            <nav class="mb-6 flex flex-wrap gap-1 border-b border-gray-200" aria-label="{{ __('Settings sections') }}">
                @php
                    $tabLabels = [
                        'general'  => __('General'),
                        'language' => __('Language'),
                        'branding' => __('Branding'),
                        'security' => __('Security'),
                    ];
                @endphp
                @foreach ($tabs as $key)
                    <a href="{{ route('settings.index', ['tab' => $key]) }}"
                       @if ($tab === $key) aria-current="page" @endif
                       data-testid="settings-tab-{{ $key }}"
                       class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition {{ $tab === $key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-800' }}">
                        {{ $tabLabels[$key] }}
                    </a>
                @endforeach
            </nav>

            @if (session('success'))
                <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800"
                     role="status" data-testid="settings-flash">
                    {{ session('success') }}
                </div>
            @endif

            {{-- ------------------------------------------------------------- General --}}
            @if ($tab === 'general')
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg" data-testid="settings-general">
                    <header>
                        <h2 class="text-lg font-medium text-gray-900">{{ __('General') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ __('Preferences for your account and the current workspace.') }}
                        </p>
                    </header>

                    <dl class="mt-6 space-y-4 text-sm">
                        <div class="flex flex-wrap justify-between gap-2">
                            <dt class="font-medium text-gray-700">{{ __('Name') }}</dt>
                            <dd class="text-gray-600">{{ $user->name }}</dd>
                        </div>
                        <div class="flex flex-wrap justify-between gap-2">
                            <dt class="font-medium text-gray-700">{{ __('Email') }}</dt>
                            <dd class="text-gray-600">{{ $user->email }}</dd>
                        </div>
                        <div class="flex flex-wrap justify-between gap-2">
                            <dt class="font-medium text-gray-700">{{ __('Workspace') }}</dt>
                            <dd class="text-gray-600">{{ $brandTenant?->name ?? __('No workspace selected') }}</dd>
                        </div>
                    </dl>

                    {{-- Personal details deliberately stay on /profile. This page is for
                         preferences; mixing the two is what made branding feel misplaced
                         in the first place. --}}
                    <p class="mt-6 text-sm text-gray-600">
                        {{ __('Your name, email and password are managed on your profile page.') }}

                        <a href="{{ route('profile.edit') }}" class="ml-1 font-medium text-indigo-600 hover:underline">
                            {{ __('Go to profile') }}
                        </a>
                    </p>
                    @if (app('tenant.context')->tenant() && app(\App\Services\ModuleManager::class)->active('ai_agent'))
                        <p class="mt-4 text-sm text-gray-600">
                            <a href="{{ route('ai.channel.edit') }}" class="font-medium text-indigo-600 hover:underline">{{ __('Connect a Telegram account to AI Assistant') }}</a>
                        </p>
                    @endif
                </div>

            {{-- --------------------------------------------------------- Language --}}
            @elseif ($tab === 'language')
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg" data-testid="settings-language">
                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-gray-900">{{ __('Language') }}</h2>
                            <p class="mt-1 text-sm text-gray-600">
                                {{ __('Choose the language you want to use throughout the application.') }}
                            </p>
                        </header>

                        <form method="post" action="{{ route('settings.language.update') }}" class="mt-6 max-w-sm">
                            @csrf
                            @method('patch')

                            <x-input-label for="locale" :value="__('Language')" />

                            <select id="locale" name="locale"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    required>
                                @foreach ($languages as $code => $language)
                                    {{-- Native name, not a translated one: a reader looking
                                         for their own language finds it by the name they
                                         know it by. --}}
                                    <option value="{{ $code }}" @selected(old('locale', $user->locale ?? config('locale.default')) === $code)>
                                        {{ $language['flag'] }} {{ $language['name'] }}
                                    </option>
                                @endforeach
                            </select>

                            <p class="mt-1 text-xs text-gray-500">
                                {{ __('This setting applies to your account only. Other members of the workspace can choose a different language.') }}
                            </p>

                            <x-input-error class="mt-2" :messages="$errors->get('locale')" />

                            <div class="mt-6">
                                <x-primary-button data-busy-label="{{ __('Saving…') }}">{{ __('Save Changes') }}</x-primary-button>
                            </div>
                        </form>
                    </section>
                </div>

            {{-- --------------------------------------------------------- Branding --}}
            @elseif ($tab === 'branding')
                {{-- Per-workspace branding. Renders its own empty/read-only state, so an
                     account without a workspace, or a member without manage_settings,
                     still sees an explanation instead of a broken form. --}}
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg" data-testid="brand-identity">
                    <div class="max-w-xl">
                        @include('settings.partials.brand-identity-form')
                    </div>
                </div>

            {{-- --------------------------------------------------------- Security --}}
            @elseif ($tab === 'security')
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg" data-testid="settings-security">
                    <header>
                        <h2 class="text-lg font-medium text-gray-900">{{ __('Security') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ __('Manage how you sign in and how your account is protected.') }}
                        </p>
                    </header>

                    <dl class="mt-6 space-y-4 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <dt class="font-medium text-gray-700">{{ __('Email') }}</dt>
                            <dd class="text-gray-600">
                                @if ($user->hasVerifiedEmail())
                                    <span class="text-green-700">{{ __('Verified') }}</span>
                                @else
                                    <span class="text-amber-700">{{ __('Not verified') }}</span>
                                @endif
                            </dd>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <dt class="font-medium text-gray-700">{{ __('Password') }}</dt>
                            <dd class="text-gray-600">{{ __('Set') }}</dd>
                        </div>
                    </dl>

                    {{-- No second password form: the existing one on /profile stays the
                         single place to change a password. --}}
                    <p class="mt-6 text-sm text-gray-600">
                        {{ __('Change your password from your profile page.') }}

                        <a href="{{ route('profile.edit') }}" class="ml-1 font-medium text-indigo-600 hover:underline">
                            {{ __('Go to profile') }}
                        </a>
                    </p>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>