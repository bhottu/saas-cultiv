<?php

namespace App\Services\Ai;

use App\Models\AiChannelLink;
use App\Models\AiPendingAction;
use App\Models\AiSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TelegramClient
{
    public function sendText(AiChannelLink $link, string $text, ?AiPendingAction $action = null): void
    {
        $keyboard = $action ? [
            'inline_keyboard' => [[
                ['text' => __('Confirm sale'), 'callback_data' => 'confirm_sale:'.$action->id],
                ['text' => __('Cancel'), 'callback_data' => 'cancel_sale:'.$action->id],
            ]],
        ] : null;

        $chunks = mb_str_split($text, 3500);
        foreach ($chunks as $index => $chunk) {
            $payload = ['chat_id' => $link->external_id, 'text' => $chunk];
            if ($keyboard && $index === array_key_last($chunks)) {
                $payload['reply_markup'] = $keyboard;
            }
            $this->call('sendMessage', $payload);
        }
    }

    public function answerCallback(string $callbackId, string $text): void
    {
        $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => mb_substr($text, 0, 180),
        ]);
    }

    public function registerWebhook(): void
    {
        $settings = AiSetting::current();
        $token = $settings->telegram_bot_token;
        $secret = $settings->telegram_webhook_secret;

        if (! $token || ! $secret) {
            Log::warning('ai.telegram.webhook_registration_failed', [
                'reason' => 'telegram_credentials_missing',
            ]);
            throw new RuntimeException('Telegram bot settings are incomplete.');
        }
        try {
            $url = $this->webhookUrl();
        } catch (RuntimeException $error) {
            Log::warning('ai.telegram.webhook_registration_failed', [
                'reason' => 'invalid_public_webhook_url',
            ]);
            throw $error;
        }

        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
        ], $token);
    }

    private function call(string $method, array $payload, ?string $token = null): array
    {
        $token ??= AiSetting::current()->telegram_bot_token;
        if (! $token) {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()
                ->post('https://api.telegram.org/bot'.$token.'/'.$method, $payload);
        } catch (ConnectionException $error) {
            Log::warning('ai.telegram.api_request_failed', [
                'method' => $method,
                'reason' => 'transport_failure',
                'exception' => $error::class,
            ]);

            throw new RuntimeException('Telegram request could not be completed.');
        } catch (Throwable $error) {
            Log::warning('ai.telegram.api_request_failed', [
                'method' => $method,
                'reason' => 'request_failure',
                'exception' => $error::class,
            ]);

            throw new RuntimeException('Telegram request could not be completed.');
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            $description = $this->safeDescription(
                (string) $response->json('description', ''),
                $token,
                AiSetting::current()->telegram_webhook_secret,
            );
            Log::warning('ai.telegram.api_request_failed', [
                'method' => $method,
                'reason' => 'telegram_api_rejected_request',
                'http_status' => $response->status(),
                'description' => $description,
            ]);

            throw new RuntimeException("Telegram request failed with HTTP {$response->status()}.");
        }

        return (array) $response->json('result', []);
    }

    private function webhookUrl(): string
    {
        $baseUrl = (string) config('app.url');
        $path = route('ai.telegram.webhook', [], false);
        $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');
        $parts = parse_url($url);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (($parts['scheme'] ?? null) !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || $this->isLocalHost($host)) {
            throw new RuntimeException('The configured webhook URL must use public HTTPS.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('The configured webhook URL must use public HTTPS.');
        }

        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 88, 443, 8443], true)) {
            throw new RuntimeException('The configured webhook URL uses an unsupported HTTPS port.');
        }

        return $url;
    }

    private function isLocalHost(string $host): bool
    {
        if (! str_contains($host, '.')) {
            return true;
        }

        foreach (['.localhost', '.local', '.test', '.internal'] as $suffix) {
            if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function safeDescription(string $description, string $token, ?string $webhookSecret): string
    {
        $tokenSecret = str_contains($token, ':') ? substr($token, strpos($token, ':') + 1) : '';
        $secrets = array_filter([$token, $tokenSecret, $webhookSecret], fn ($secret) => $secret !== null && $secret !== '');
        $safe = str_replace($secrets, '[redacted]', $description);
        $safe = preg_replace('~https?://\S+~i', '[url]', $safe) ?? '';

        return mb_substr(trim($safe), 0, 300);
    }
}
