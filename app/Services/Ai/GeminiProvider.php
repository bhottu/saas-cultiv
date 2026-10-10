<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class GeminiProvider implements AiProvider
{
    public function __construct(private readonly AiSetting $settings, private readonly string $model) {}

    public function complete(array $messages, array $tools): AiProviderResponse
    {
        $key = $this->settings->apiKeyFor('gemini');
        if (! $key) {
            throw new AiProviderException('gemini', $this->model, 'credential_missing');
        }

        $system = [];
        $contents = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $system[] = ['text' => $message['content'] ?? ''];
                continue;
            }

            $role = $message['role'] === 'assistant' ? 'model' : 'user';
            if ($message['role'] === 'tool') {
                $result = json_decode((string) ($message['content'] ?? ''), true);
                $contents[] = [
                    'role' => 'user',
                    'parts' => [[
                        'functionResponse' => [
                            'name' => $message['name'],
                            'response' => is_array($result) ? $result : ['result' => (string) $message['content']],
                        ],
                    ]],
                ];
                continue;
            }

            $parts = [];
            if (! empty($message['content'])) {
                $parts[] = ['text' => $message['content']];
            }
            if (isset($message['provider_metadata']['gemini_content_parts'])
                && is_array($message['provider_metadata']['gemini_content_parts'])) {
                $parts = $message['provider_metadata']['gemini_content_parts'];
            } else {
                foreach (($message['tool_calls'] ?? []) as $call) {
                    $arguments = json_decode($call['function']['arguments'], true);
                    $parts[] = ['functionCall' => [
                        'name' => $call['function']['name'],
                        'args' => is_array($arguments) ? $arguments : [],
                    ]];
                }
            }
            $contents[] = ['role' => $role, 'parts' => $parts];
        }

        $payload = ['contents' => $contents];
        if ($system !== []) {
            $payload['systemInstruction'] = ['parts' => $system];
        }
        if ($tools !== []) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(fn (array $tool) => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $this->geminiSchema($tool['parameters']),
                ], $tools),
            ]];
        }

        $connectTimeout = 5;
        $requestTimeout = 35;
        $startedAt = hrtime(true);

        try {
            $response = Http::retry(3, fn (int $attempt): int => 1000 * (2 ** ($attempt - 1)), function (Throwable $error): bool {
                if ($error instanceof ConnectionException) {
                    return ! in_array($this->transportErrorType($error), ['tls_error', 'certificate_error'], true);
                }

                return $error instanceof RequestException
                    && (in_array($error->response->status(), [408, 429], true)
                        || $error->response->serverError());
            }, false)
                ->acceptJson()->asJson()->withHeaders(['x-goog-api-key' => $key])
                ->connectTimeout($connectTimeout)->timeout($requestTimeout)->withoutRedirecting()
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$this->model.':generateContent', $payload);
        } catch (ConnectionException $error) {
            throw AiProviderException::transportFailure(
                'gemini',
                $this->model,
                $error,
                $key,
                (int) ((hrtime(true) - $startedAt) / 1_000_000),
                $connectTimeout,
                $requestTimeout,
            );
        } catch (Throwable $error) {
            throw new AiProviderException('gemini', $this->model, 'request_failure', previous: $error);
        }

        if (! $response->successful()) {
            throw AiProviderException::httpFailure(
                'gemini',
                $this->model,
                $response->status(),
                is_string($response->json('error.status')) ? $response->json('error.status') : null,
                is_string($response->json('error.message')) ? $response->json('error.message') : null,
                $key,
                (int) ((hrtime(true) - $startedAt) / 1_000_000),
            );
        }

        $parts = $response->json('candidates.0.content.parts');
        if (! is_array($parts)) {
            throw new AiProviderException('gemini', $this->model, 'invalid_response');
        }

        $text = '';
        $calls = [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                throw new AiProviderException('gemini', $this->model, 'invalid_response');
            }
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
            if (isset($part['functionCall'])) {
                $functionCall = $part['functionCall'];
                if (! is_array($functionCall)
                    || ! is_string($functionCall['name'] ?? null)
                    || ! is_array($functionCall['args'] ?? [])) {
                    throw new AiProviderException('gemini', $this->model, 'invalid_tool_arguments');
                }
                $calls[] = [
                    'id' => 'gemini-'.count($calls),
                    'name' => $functionCall['name'],
                    'arguments' => $functionCall['args'] ?? [],
                ];
            }
        }

        return new AiProviderResponse($text !== '' ? $text : null, $calls, [
            'gemini_content_parts' => $parts,
        ]);
    }

    private function transportErrorType(ConnectionException $error): string
    {
        if (preg_match('/cURL error (\d+)/i', $error->getMessage(), $matches) !== 1) {
            return 'network_error';
        }

        return match ((int) $matches[1]) {
            6 => 'dns_failure',
            7 => 'connection_refused',
            28 => preg_match('/failed to connect|connection timed out/i', $error->getMessage()) === 1
                ? 'connection_timeout'
                : 'request_timeout',
            35 => 'tls_error',
            60 => 'certificate_error',
            default => 'curl_error',
        };
    }

    private function geminiSchema(array $schema): array
    {
        $types = ['object' => 'OBJECT', 'string' => 'STRING', 'integer' => 'INTEGER', 'number' => 'NUMBER', 'boolean' => 'BOOLEAN', 'array' => 'ARRAY'];
        $mapped = ['type' => $types[$schema['type'] ?? 'object'] ?? 'OBJECT'];

        foreach (['description', 'enum', 'required'] as $key) {
            if (isset($schema[$key])) {
                $mapped[$key] = $schema[$key];
            }
        }
        if (isset($schema['properties'])) {
            $mapped['properties'] = [];
            foreach ($schema['properties'] as $name => $property) {
                $mapped['properties'][$name] = $this->geminiSchema($property);
            }
        }
        if (isset($schema['items'])) {
            $mapped['items'] = $this->geminiSchema($schema['items']);
        }

        return $mapped;
    }
}
