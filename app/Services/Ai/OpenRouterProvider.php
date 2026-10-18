<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OpenRouter is an OpenAI-compatible aggregator, so this provider mirrors the
 * OpenAI request/response shape (chat/completions + function tool calls) and reuses the
 * exact same normalized messages/tools the rest of Cultiv AI already produces. The only
 * differences are the base URL and the attribution headers OpenRouter asks for.
 *
 * The base URL is configuration-driven (config/services.php → openrouter.base_url) so a
 * self-hosted or proxied gateway can be pointed at without a code change, while the API
 * key always comes from the workspace-scoped, encrypted `openrouter_api_key` credential.
 */
class OpenRouterProvider implements AiProvider
{
    public function __construct(private readonly AiSetting $settings, private readonly string $model) {}

    public function complete(array $messages, array $tools): AiProviderResponse
    {
        $key = $this->settings->apiKeyFor('openrouter');
        if (! $key) {
            throw new AiProviderException('openrouter', $this->model, 'credential_missing');
        }

        $baseUrl = self::baseUrl();

        $payload = [
            'model' => $this->model,
            'messages' => array_map(fn (array $message) => $this->mapMessage($message), $messages),
            'temperature' => 0.2,
        ];
        if ($tools !== []) {
            $payload['tools'] = array_map(fn (array $tool) => [
                'type' => 'function',
                'function' => $tool,
            ], $tools);
        }

        try {
            $response = Http::acceptJson()->asJson()->withToken($key)
                ->withHeaders(self::attributionHeaders())
                ->connectTimeout(5)->timeout(35)->withoutRedirecting()
                ->post($baseUrl.'/chat/completions', $payload);
        } catch (ConnectionException $error) {
            throw new AiProviderException('openrouter', $this->model, 'transport_failure', previous: $error);
        } catch (Throwable $error) {
            throw new AiProviderException('openrouter', $this->model, 'request_failure', previous: $error);
        }

        if (! $response->successful()) {
            // OpenRouter reports `error.message` (and sometimes a numeric/string `error.code`);
            // both are passed through the shared redacting httpFailure() helper.
            throw AiProviderException::httpFailure(
                'openrouter',
                $this->model,
                $response->status(),
                is_string($response->json('error.code')) ? $response->json('error.code') : null,
                is_string($response->json('error.message')) ? $response->json('error.message') : null,
                $key,
            );
        }

        $message = $response->json('choices.0.message');
        if (! is_array($message)) {
            throw new AiProviderException('openrouter', $this->model, 'invalid_response');
        }

        $calls = [];
        foreach (($message['tool_calls'] ?? []) as $call) {
            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            if (! is_array($arguments)) {
                throw new AiProviderException('openrouter', $this->model, 'invalid_tool_arguments');
            }
            $calls[] = [
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($call['function']['name'] ?? ''),
                'arguments' => $arguments,
            ];
        }

        return new AiProviderResponse($this->text($message['content'] ?? null), $calls);
    }

    public static function baseUrl(): string
    {
        return rtrim((string) config('services.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/');
    }

    /**
     * Optional attribution headers OpenRouter uses for app ranking / usage analytics.
     * Only set when configured, so an unconfigured install sends no empty headers.
     *
     * @return array<string, string>
     */
    public static function attributionHeaders(): array
    {
        $headers = [];
        if (filled($referer = config('services.openrouter.site_url'))) {
            $headers['HTTP-Referer'] = (string) $referer;
        }
        if (filled($title = config('services.openrouter.title'))) {
            $headers['X-Title'] = (string) $title;
        }

        return $headers;
    }

    private function mapMessage(array $message): array
    {
        $mapped = ['role' => $message['role'], 'content' => $message['content'] ?? ''];
        foreach (['tool_call_id', 'name', 'tool_calls'] as $key) {
            if (isset($message[$key])) {
                $mapped[$key] = $message[$key];
            }
        }

        return $mapped;
    }

    private function text(mixed $content): ?string
    {
        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            return implode('', array_map(
                fn (array $part) => (string) ($part['text'] ?? ''),
                array_filter($content, 'is_array'),
            ));
        }

        return null;
    }
}
