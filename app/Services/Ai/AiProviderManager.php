<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AiProviderManager
{
    public function primary(): AiProvider
    {
        $settings = AiSetting::current();

        return $this->make($settings, $settings->provider, $settings->model);
    }

    public function complete(array $messages, array $tools): AiProviderResponse
    {
        $settings = AiSetting::current();
        $primary = $this->make($settings, $settings->provider, $settings->model);

        try {
            return $primary->complete($messages, $tools);
        } catch (Throwable $primaryError) {
            if (! $settings->fallback_provider || ! $settings->fallback_model) {
                throw $primaryError;
            }

            Log::warning('ai.provider.primary_failed_using_fallback', [
                'provider' => $settings->provider,
                'model' => $settings->model,
                'fallback_provider' => $settings->fallback_provider,
                'fallback_model' => $settings->fallback_model,
                'reason' => $primaryError instanceof AiProviderException ? $primaryError->reason : 'provider_or_transport_error',
                'provider_status' => $primaryError instanceof AiProviderException ? $primaryError->status : null,
                'provider_api_status' => $primaryError instanceof AiProviderException ? $primaryError->apiStatus : null,
                'duration_ms' => $primaryError instanceof AiProviderException ? $primaryError->elapsedMilliseconds : null,
                'exception' => $primaryError::class,
            ]);

            return $this->make($settings, $settings->fallback_provider, $settings->fallback_model)
                ->complete($messages, $tools);
        }
    }

    public function testPrimary(): AiProviderResponse
    {
        $settings = AiSetting::current();

        try {
            return $this->make($settings, $settings->provider, $settings->model)->complete([
                ['role' => 'user', 'content' => 'Reply with the single word OK.'],
            ], []);
        } catch (Throwable $error) {
            Log::warning('ai.provider.connection_test_failed', [
                'provider' => $settings->provider,
                'model' => $settings->model,
                'reason' => $error instanceof AiProviderException
                    ? $error->reason
                    : ($error instanceof ConnectionException ? 'transport_failure' : 'configuration_or_provider_error'),
                'http_status' => $error instanceof AiProviderException ? $error->status : null,
                'api_status' => $error instanceof AiProviderException ? $error->apiStatus : null,
                'api_message' => $error instanceof AiProviderException ? $error->apiMessage : null,
                'transport_type' => $error instanceof AiProviderException ? $error->transportType : null,
                'host' => $error instanceof AiProviderException ? $error->host : null,
                'port' => $error instanceof AiProviderException ? $error->port : null,
                'technical_detail' => $error instanceof AiProviderException ? $error->technicalDetail : null,
                'elapsed_ms' => $error instanceof AiProviderException ? $error->elapsedMilliseconds : null,
                'exception' => $error::class,
            ]);

            throw $error;
        }
    }

    private function make(AiSetting $settings, string $provider, string $model): AiProvider
    {
        return match ($provider) {
            'openai' => new OpenAiProvider($settings, $model),
            'gemini' => new GeminiProvider($settings, $model),
            'ollama' => new OllamaProvider($settings, $model),
            default => throw new RuntimeException('The configured AI provider is not supported.'),
        };
    }
}
