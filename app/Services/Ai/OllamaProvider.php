<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class OllamaProvider implements AiProvider
{
    public function __construct(private readonly AiSetting $settings, private readonly string $model) {}

    public function complete(array $messages, array $tools): AiProviderResponse
    {
        $payload = [
            'model' => $this->model,
            'messages' => array_map(fn (array $message) => $this->mapMessage($message), $messages),
            'stream' => false,
        ];
        if ($tools !== []) {
            $payload['tools'] = array_map(fn (array $tool) => [
                'type' => 'function',
                'function' => $tool,
            ], $tools);
        }

        $baseUrl = rtrim((string) $this->settings->ollama_base_url, '/');
        try {
            $response = Http::acceptJson()->asJson()
                ->connectTimeout(5)->timeout(60)->withoutRedirecting()
                ->post($baseUrl.'/api/chat', $payload);
        } catch (ConnectionException $error) {
            throw new AiProviderException('ollama', $this->model, 'transport_failure', previous: $error);
        } catch (Throwable $error) {
            throw new AiProviderException('ollama', $this->model, 'request_failure', previous: $error);
        }

        if (! $response->successful()) {
            throw AiProviderException::httpFailure('ollama', $this->model, $response->status());
        }

        $message = $response->json('message');
        if (! is_array($message)) {
            throw new AiProviderException('ollama', $this->model, 'invalid_response');
        }

        $calls = [];
        foreach (($message['tool_calls'] ?? []) as $call) {
            $function = $call['function'] ?? [];
            $calls[] = [
                'id' => 'ollama-'.count($calls),
                'name' => (string) ($function['name'] ?? ''),
                'arguments' => (array) ($function['arguments'] ?? []),
            ];
        }

        return new AiProviderResponse(isset($message['content']) ? (string) $message['content'] : null, $calls);
    }

    private function mapMessage(array $message): array
    {
        if ($message['role'] === 'tool') {
            return ['role' => 'tool', 'content' => $message['content'] ?? '', 'name' => $message['name'] ?? ''];
        }

        if ($message['role'] !== 'assistant' || ! isset($message['tool_calls'])) {
            return $message;
        }

        $message['tool_calls'] = array_map(function (array $call): array {
            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);

            return [
                'function' => [
                    'name' => $call['function']['name'],
                    'arguments' => is_array($arguments) ? $arguments : [],
                ],
            ];
        }, $message['tool_calls']);

        return $message;
    }
}
