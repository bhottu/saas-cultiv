<?php

namespace App\Http\Controllers\Admin;

use App\Models\AiSetting;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\TelegramClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class AiSettingsController extends AdminController
{
    public function edit()
    {
        $settings = AiSetting::current();

        return view('admin.ai.index', [
            'settings' => $settings,
            'providers' => AiSetting::PROVIDERS,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(AiSetting::PROVIDERS)],
            'model' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'fallback_provider' => ['nullable', Rule::in(AiSetting::PROVIDERS)],
            'fallback_model' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'openai_api_key' => ['nullable', 'string', 'max:4096'],
            'gemini_api_key' => ['nullable', 'string', 'max:4096'],
            'ollama_base_url' => ['required', 'url:http,https', 'max:255'],
            'telegram_bot_token' => ['nullable', 'string', 'max:512'],
            'clear_openai_api_key' => ['nullable', 'boolean'],
            'clear_gemini_api_key' => ['nullable', 'boolean'],
            'clear_telegram_bot_token' => ['nullable', 'boolean'],
        ]);

        if ($data['fallback_provider'] && $data['fallback_provider'] === $data['provider']) {
            return back()->withInput()->withErrors(['fallback_provider' => __('Choose a different provider for fallback.')]);
        }
        if ((bool) $data['fallback_provider'] !== (bool) ($data['fallback_model'] ?? null)) {
            return back()->withInput()->withErrors(['fallback_model' => __('Set both fallback provider and model, or leave both empty.')]);
        }

        $parts = parse_url($data['ollama_base_url']);
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return back()->withInput()->withErrors(['ollama_base_url' => __('The Ollama URL cannot contain credentials, a query string or a fragment.')]);
        }

        $settings = AiSetting::current();
        $attributes = [
            'provider' => $data['provider'],
            'model' => trim($data['model']),
            'fallback_provider' => $data['fallback_provider'] ?: null,
            'fallback_model' => isset($data['fallback_model']) ? trim($data['fallback_model']) : null,
            'ollama_base_url' => rtrim($data['ollama_base_url'], '/'),
        ];
        $changedSecrets = [];

        foreach (['openai_api_key', 'gemini_api_key', 'telegram_bot_token'] as $field) {
            $clearField = 'clear_'.$field;
            if ($request->boolean($clearField)) {
                $attributes[$field] = null;
                $changedSecrets[] = $field;
            } elseif (filled($data[$field] ?? null)) {
                $attributes[$field] = trim($data[$field]);
                $changedSecrets[] = $field;
            }
            if ($request->boolean('clear_telegram_bot_token')) {
                $attributes['telegram_webhook_secret'] = null;
            }
        }

        if (! $settings->telegram_webhook_secret && ! empty($attributes['telegram_bot_token'] ?? $settings->telegram_bot_token)) {
            $attributes['telegram_webhook_secret'] = bin2hex(random_bytes(32));
        }

        $settings->forceFill($attributes)->save();
        $this->audit($request, 'ai.settings.updated', null, [
            'changed' => [...array_keys($attributes), ...$changedSecrets],
            'secrets_changed' => $changedSecrets,
        ]);

        return redirect()->route('admin.ai.edit')
            ->with('status', ['type' => 'success', 'message' => __('AI settings saved. Credentials are encrypted at rest.')]);
    }

    public function testProvider(Request $request, AiProviderManager $providers)
    {
        try {
            $response = $providers->testPrimary();
            if ($response->text === null && $response->toolCalls === []) {
                throw new \RuntimeException('The provider returned an empty response.');
            }

            $this->audit($request, 'ai.provider.test_succeeded', null, [
                'provider' => AiSetting::current()->provider,
            ]);

            return back()->with('status', ['type' => 'success', 'message' => __('The selected AI provider responded successfully.')]);
        } catch (Throwable $error) {
            if (! $error instanceof \App\Services\Ai\AiProviderException) {
                Log::warning('ai.provider.connection_test_failed', [
                    'provider' => AiSetting::current()->provider,
                    'model' => AiSetting::current()->model,
                    'reason' => 'configuration_or_provider_error',
                    'exception' => $error::class,
                ]);
            }
            $this->audit($request, 'ai.provider.test_failed', null, [
                'provider' => AiSetting::current()->provider,
                'exception' => $error::class,
            ]);

            $message = $error instanceof \App\Services\Ai\AiProviderException
                ? $error->diagnosticMessage()
                : __('AI provider connection failed. Check the saved configuration and server logs.');

            return back()->with('status', ['type' => 'error', 'message' => $message]);
        }
    }

    public function registerTelegramWebhook(Request $request, TelegramClient $telegram)
    {
        try {
            $telegram->registerWebhook();
            $this->audit($request, 'ai.telegram.webhook_registered', null, []);

            return back()->with('status', ['type' => 'success', 'message' => __('Telegram webhook registered.')]);
        } catch (Throwable $error) {
            Log::warning('ai.telegram.webhook_registration_failed', ['exception' => $error::class]);
            $this->audit($request, 'ai.telegram.webhook_registration_failed', null, [
                'exception' => $error::class,
            ]);

            return back()->with('status', ['type' => 'error', 'message' => __('Telegram webhook could not be registered. Check the bot token and HTTPS application URL.')]);
        }
    }
}
