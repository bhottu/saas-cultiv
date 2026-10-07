<x-admin-shell :title="__('AI Assistant')"
               :subtitle="__('Configure the AI provider and Telegram channel for the platform.')"
               :editable="true">
    <div class="space-y-6">
        @if (session('status'))
            <div class="rounded-lg border px-4 py-3 text-sm {{ (session('status.type') ?? 'success') === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">
                {{ session('status.message') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.ai.update') }}" class="space-y-6">
            @csrf
            @method('PUT')

            <section class="admin-card p-5 sm:p-6">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ __('AI provider') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('Provider credentials are encrypted at rest and never shown after saving.') }}</p>
                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <div>
                        <x-input-label for="provider" :value="__('Primary provider')" />
                        <select id="provider" name="provider" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($providers as $provider)
                                <option value="{{ $provider }}" @selected(old('provider', $settings->provider) === $provider)>{{ ucfirst($provider) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('provider')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="model" :value="__('Primary model')" />
                        <x-text-input id="model" name="model" class="mt-1 block w-full" required maxlength="120"
                                      :value="old('model', $settings->model)" />
                        <x-input-error :messages="$errors->get('model')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="fallback_provider" :value="__('Fallback provider')" />
                        <select id="fallback_provider" name="fallback_provider" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">{{ __('None') }}</option>
                            @foreach ($providers as $provider)
                                <option value="{{ $provider }}" @selected(old('fallback_provider', $settings->fallback_provider) === $provider)>{{ ucfirst($provider) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('fallback_provider')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="fallback_model" :value="__('Fallback model')" />
                        <x-text-input id="fallback_model" name="fallback_model" class="mt-1 block w-full" maxlength="120"
                                      :value="old('fallback_model', $settings->fallback_model)" />
                        <x-input-error :messages="$errors->get('fallback_model')" class="mt-1" />
                    </div>
                    <div class="md:col-span-2">
                        <x-input-label for="ollama_base_url" :value="__('Ollama base URL')" />
                        <x-text-input id="ollama_base_url" name="ollama_base_url" type="url" class="mt-1 block w-full"
                                      required maxlength="255" :value="old('ollama_base_url', $settings->ollama_base_url)" />
                        <x-input-error :messages="$errors->get('ollama_base_url')" class="mt-1" />
                    </div>
                </div>

                <div class="mt-6 grid gap-5 md:grid-cols-2">
                    @foreach (['openai_api_key' => 'OpenAI API key', 'gemini_api_key' => 'Gemini API key'] as $field => $label)
                        <div>
                            <x-input-label :for="$field" :value="__($label)" />
                            <x-text-input :id="$field" :name="$field" type="password" autocomplete="new-password"
                                          class="mt-1 block w-full" maxlength="4096" />
                            <p class="mt-1 text-xs text-gray-500">
                                {{ $settings->{$field} ? __('A credential is saved. Leave blank to keep it.') : __('No credential saved.') }}
                            </p>
                            @if ($settings->{$field})
                                <label class="mt-2 inline-flex items-center gap-2 text-xs text-red-700">
                                    <input type="checkbox" name="clear_{{ $field }}" value="1" class="rounded border-gray-300">
                                    {{ __('Remove saved credential') }}
                                </label>
                            @endif
                            <x-input-error :messages="$errors->get($field)" class="mt-1" />
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="admin-card p-5 sm:p-6">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ __('Telegram') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('The webhook requires a publicly reachable HTTPS application URL.') }}</p>
                <div class="mt-5">
                    <x-input-label for="telegram_bot_token" :value="__('Telegram bot token')" />
                    <x-text-input id="telegram_bot_token" name="telegram_bot_token" type="password" autocomplete="new-password"
                                  class="mt-1 block w-full" maxlength="512" />
                    <p class="mt-1 text-xs text-gray-500">
                        {{ $settings->telegram_bot_token ? __('A bot token is saved. Leave blank to keep it.') : __('No bot token saved.') }}
                    </p>
                    @if ($settings->telegram_bot_token)
                        <label class="mt-2 inline-flex items-center gap-2 text-xs text-red-700">
                            <input type="checkbox" name="clear_telegram_bot_token" value="1" class="rounded border-gray-300">
                            {{ __('Remove saved credential') }}
                        </label>
                    @endif
                    <x-input-error :messages="$errors->get('telegram_bot_token')" class="mt-1" />
                </div>
            </section>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">{{ __('Save settings') }}</button>
            </div>
        </form>

        <div class="flex flex-wrap gap-3">
            <form method="POST" action="{{ route('admin.ai.test') }}">
                @csrf
                <button type="submit" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('Test selected provider') }}</button>
            </form>
            <form method="POST" action="{{ route('admin.ai.telegram-webhook') }}">
                @csrf
                <button type="submit" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('Register Telegram webhook') }}</button>
            </form>
        </div>
    </div>
</x-admin-shell>
