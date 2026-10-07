<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenAiProvider implements AiProvider
{
    public function __construct(private readonly AiSetting $settings, private readonly string $model) {}

    public function complete(array $messages, array $tools): AiProviderResponse
    {
        $key = $this->settings->apiKeyFor('openai');
        if (! $key) {
            throw new AiProviderException('openai', $this->model, 'credential_missing');
        }

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
                ->connectTimeout(5)->timeout(35)->withoutRedirecting()
                ->post('https://api.openai.com/v1/chat/completions', $payload);
        } catch (ConnectionException $error) {
            throw new AiProviderException('openai', $this->model, 'transport_failure', previous: $error);
        } catch (Throwable $error) {
            throw new AiProviderException('openai', $this->model, 'request_failure', previous: $error);
        }

        if (! $response->successful()) {
            throw AiProviderException::httpFailure('openai', $this->model, $response->status());
        }

        $message = $response->json('choices.0.message');
        if (! is_array($message)) {
            throw new AiProviderException('openai', $this->model, 'invalid_response');
        }

        $calls = [];
        foreach (($message['tool_calls'] ?? []) as $call) {
            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            if (! is_array($arguments)) {
                throw new AiProviderException('openai', $this->model, 'invalid_tool_arguments');
            }
            $calls[] = [
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($call['function']['name'] ?? ''),
                'arguments' => $arguments,
            ];
        }

        return new AiProviderResponse($this->text($message['content'] ?? null), $calls);
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
