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

            @php
                // Build the OpenRouter model <option> labels once for both the primary and
                // fallback dropdowns: "Name — id · FREE · Tool calling · 128k". The stored
                // value is always the raw OpenRouter model ID, never this label.
                $openrouterModelOptions = collect($openrouterModels)->map(function (array $m): array {
                    $label = $m['name'].' — '.$m['id'];
                    if ($m['free']) {
                        $label .= ' · '.__('Free');
                    }
                    if ($m['tool_calling']) {
                        $label .= ' · '.__('Tool calling');
                    }
                    if ($m['context_length']) {
                        $label .= ' · '.((int) round($m['context_length'] / 1024)).'k';
                    }

                    return ['id' => $m['id'], 'label' => $label];
                })->values();
                $primaryIsOpenRouter = old('provider', $settings->provider) === 'openrouter';
                $fallbackIsOpenRouter = old('fallback_provider', $settings->fallback_provider) === 'openrouter';
                $orEmptyMessage = __('No OpenRouter models are available yet. Save an API key, then refresh.');
                $primaryOrStatus = $openrouterModelsError ?: ($primaryIsOpenRouter && $openrouterModelOptions === [] ? $orEmptyMessage : '');
                $fallbackOrStatus = $openrouterModelsError ?: ($fallbackIsOpenRouter && $openrouterModelOptions === [] ? $orEmptyMessage : '');
            @endphp

            <section class="admin-card p-5 sm:p-6">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ __('AI provider') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('Provider credentials are encrypted at rest and never shown after saving.') }}</p>
                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <div>
                        <x-input-label for="provider" :value="__('Primary provider')" />
                        <select id="provider" name="provider" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($providers as $provider)
                                <option value="{{ $provider }}" @selected(old('provider', $settings->provider) === $provider)>{{ $provider === 'openrouter' ? 'OpenRouter' : ucfirst($provider) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('provider')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="model" :value="__('Primary model')" />

                        {{-- OpenRouter dynamic model dropdown. The text input below stays the
                             canonical `model` field; when OpenRouter is chosen this select is
                             shown, the text input is hidden, and the script at the bottom of
                             the page keeps the text input in sync so the saved value is always
                             the raw OpenRouter model ID (e.g. `google/gemini-2.5-flash:free`). --}}
                        <div data-or-select="primary" class="{{ $primaryIsOpenRouter ? '' : 'hidden' }}">
                            <select id="model_openrouter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ __('Select an OpenRouter model') }}</option>
                                @foreach ($openrouterModelOptions as $openrouterOption)
                                    <option value="{{ $openrouterOption['id'] }}" @selected(old('model', $settings->model) === $openrouterOption['id'])>{{ $openrouterOption['label'] }}</option>
                                @endforeach
                            </select>
                            <div class="mt-1 flex items-center gap-3">
                                <button type="button" data-or-refresh="primary"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-800">
                                    ↻ {{ __('Refresh model list') }}
                                </button>
                            </div>
                            <p data-or-status="primary" role="status"
                               class="mt-1 text-xs {{ $primaryOrStatus ? 'text-amber-700' : 'hidden' }}">{{ $primaryOrStatus }}</p>
                        </div>

                        <div data-model-text="primary" class="{{ $primaryIsOpenRouter ? 'hidden' : '' }}">
                            <x-text-input id="model" name="model" class="mt-1 block w-full" required maxlength="120"
                                          :value="old('model', $settings->model)" />
                        </div>
                        <x-input-error :messages="$errors->get('model')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="fallback_provider" :value="__('Fallback provider')" />
                        <select id="fallback_provider" name="fallback_provider" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">{{ __('None') }}</option>
                            @foreach ($providers as $provider)
                                <option value="{{ $provider }}" @selected(old('fallback_provider', $settings->fallback_provider) === $provider)>{{ $provider === 'openrouter' ? 'OpenRouter' : ucfirst($provider) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('fallback_provider')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="fallback_model" :value="__('Fallback model')" />

                        {{-- Same OpenRouter behaviour as the primary model field above. --}}
                        <div data-or-select="fallback" class="{{ $fallbackIsOpenRouter ? '' : 'hidden' }}">
                            <select id="fallback_model_openrouter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ __('Select an OpenRouter model') }}</option>
                                @foreach ($openrouterModelOptions as $openrouterOption)
                                    <option value="{{ $openrouterOption['id'] }}" @selected(old('fallback_model', $settings->fallback_model) === $openrouterOption['id'])>{{ $openrouterOption['label'] }}</option>
                                @endforeach
                            </select>
                            <div class="mt-1 flex items-center gap-3">
                                <button type="button" data-or-refresh="fallback"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-800">
                                    ↻ {{ __('Refresh model list') }}
                                </button>
                            </div>
                            <p data-or-status="fallback" role="status"
                               class="mt-1 text-xs {{ $fallbackOrStatus ? 'text-amber-700' : 'hidden' }}">{{ $fallbackOrStatus }}</p>
                        </div>

                        <div data-model-text="fallback" class="{{ $fallbackIsOpenRouter ? 'hidden' : '' }}">
                            <x-text-input id="fallback_model" name="fallback_model" class="mt-1 block w-full" maxlength="120"
                                          :value="old('fallback_model', $settings->fallback_model)" />
                        </div>
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
                    @foreach (['openai_api_key' => 'OpenAI API key', 'gemini_api_key' => 'Gemini API key', 'openrouter_api_key' => 'OpenRouter API key'] as $field => $label)
                        <div class="{{ $field === 'openrouter_api_key' ? 'md:col-span-2' : '' }}">
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

        <script>
            // Server-provided config: the model endpoint (same admin session/auth) and the
            // translated UI strings. The API key NEVER reaches the browser — the backend
            // reads the saved, encrypted credential on every model request.
            window.CultivOpenRouter = {
                endpoint: @json(route('admin.ai.openrouter.models')),
                i18n: {
                    placeholder: @json(__('Select an OpenRouter model')),
                    loading: @json(__('Loading models...')),
                    network: @json(__('OpenRouter cannot be reached right now.')),
                    generic: @json(__('The OpenRouter model list could not be loaded.')),
                    savedGone: @json(__('The saved model is no longer available.')),
                    free: @json(__('Free')),
                    tools: @json(__('Tool calling')),
                },
            };

            (function () {
                var cfg = window.CultivOpenRouter;

                function label(m) {
                    var text = m.name + ' — ' + m.id;
                    if (m.is_free) { text += ' · ' + cfg.i18n.free; }
                    if (m.supports_tools) { text += ' · ' + cfg.i18n.tools; }
                    if (m.context_length) { text += ' · ' + Math.round(m.context_length / 1024) + 'k'; }
                    return text;
                }

                // When a provider is set to "openrouter", swap its model text input for the
                // OpenRouter model dropdown. The text input stays in the DOM (keeping its
                // name="model" / name="fallback_model") and is kept in sync with the select,
                // so the value submitted is always the raw OpenRouter model ID — the select
                // itself carries no `name` and therefore never posts on its own.
                function bind(providerId, kind, selectId, textId) {
                    var provider = document.getElementById(providerId);
                    var selectWrap = document.querySelector('[data-or-select="' + kind + '"]');
                    var textWrap = document.querySelector('[data-model-text="' + kind + '"]');
                    var select = document.getElementById(selectId);
                    var text = document.getElementById(textId);
                    var status = document.querySelector('[data-or-status="' + kind + '"]');
                    var refresh = document.querySelector('[data-or-refresh="' + kind + '"]');
                    if (!provider || !selectWrap || !textWrap || !select || !text) {
                        return;
                    }

                    // The persisted OpenRouter model, captured once at load. Only meaningful
                    // when the saved provider is already OpenRouter; used to re-select it after
                    // every re-fetch and to keep it from being silently dropped on failure.
                    var savedModel = provider.value === 'openrouter' ? text.value : '';

                    function setStatus(message, type) {
                        if (!status) { return; }
                        if (!message) {
                            status.textContent = '';
                            status.classList.add('hidden');
                            status.classList.remove('text-amber-700', 'text-gray-500');
                            return;
                        }
                        status.classList.remove('hidden');
                        status.classList.toggle('text-amber-700', type === 'error');
                        status.classList.toggle('text-gray-500', type === 'loading');
                        status.textContent = message;
                    }

                    function renderOptions(models) {
                        var found = false;
                        select.innerHTML = '';
                        var placeholder = document.createElement('option');
                        placeholder.value = '';
                        placeholder.textContent = cfg.i18n.placeholder;
                        select.appendChild(placeholder);

                        models.forEach(function (m) {
                            var option = document.createElement('option');
                            option.value = m.id;
                            option.textContent = label(m);
                            if (savedModel && m.id === savedModel) {
                                option.selected = true;
                                found = true;
                            }
                            select.appendChild(option);
                        });

                        // Keep a persisted model visible/selectable even if it is no longer
                        // offered, so it is never silently cleared from the hidden field.
                        if (savedModel && !found) {
                            var stale = document.createElement('option');
                            stale.value = savedModel;
                            stale.textContent = savedModel;
                            stale.selected = true;
                            select.insertBefore(stale, placeholder.nextSibling);
                        }

                        text.value = select.value; // keep the canonical `model` field in sync
                        return { found: found, count: models.length };
                    }

                    function load(isRefresh) {
                        if (provider.value !== 'openrouter') { return; }
                        setStatus(cfg.i18n.loading, 'loading');
                        if (refresh) { refresh.disabled = true; }
                        var url = cfg.endpoint + (isRefresh ? '?refresh=1' : '');

                        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                            .then(function (r) { return r.json().then(function (payload) { return { status: r.status, payload: payload }; }); })
                            .then(function (envelope) {
                                var payload = envelope.payload;
                                if (!payload || payload.error) {
                                    // Do NOT clear the server-rendered options on failure: keep what
                                    // the page loaded with and surface the safe backend message.
                                    setStatus((payload && payload.error) || cfg.i18n.generic, 'error');
                                    return;
                                }
                                var result = renderOptions(payload.models || []);
                                if (result.count === 0) {
                                    setStatus(cfg.i18n.generic, 'error');
                                } else if (savedModel && !result.found) {
                                    setStatus(cfg.i18n.savedGone, 'error');
                                } else {
                                    setStatus('', 'loading'); // clear on success
                                }
                            })
                            .catch(function () {
                                // Keep the server-rendered options; only explain the refresh failed.
                                setStatus(cfg.i18n.network, 'error');
                            })
                            .finally(function () {
                                if (refresh) { refresh.disabled = false; }
                            });
                    }

                    function sync() {
                        var isOpenRouter = provider.value === 'openrouter';
                        if (isOpenRouter) {
                            if (!textWrap.classList.contains('hidden')) {
                                // Remember the non-OpenRouter model so switching back restores it.
                                text.dataset.lastValue = text.value;
                            }
                            text.value = select.value;
                        } else if (text.dataset.lastValue !== undefined) {
                            text.value = text.dataset.lastValue;
                        }
                        selectWrap.classList.toggle('hidden', !isOpenRouter);
                        textWrap.classList.toggle('hidden', isOpenRouter);
                    }

                    provider.addEventListener('change', function () {
                        sync();
                        if (provider.value === 'openrouter') { load(false); }
                    });
                    if (refresh) { refresh.addEventListener('click', function () { load(true); }); }
                    select.addEventListener('change', function () { text.value = select.value; });

                    sync();
                    if (provider.value === 'openrouter') { load(false); } // auto-load on page open
                }

                bind('provider', 'primary', 'model_openrouter', 'model');
                bind('fallback_provider', 'fallback', 'fallback_model_openrouter', 'fallback_model');
            })();
        </script>
    </div>
</x-admin-shell>
