<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $reason,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
        public readonly ?string $apiStatus = null,
        public readonly ?string $apiMessage = null,
        public readonly ?string $transportType = null,
        public readonly ?string $host = null,
        public readonly ?int $port = null,
        public readonly ?string $technicalDetail = null,
        public readonly ?int $elapsedMilliseconds = null,
    ) {
        parent::__construct('AI provider request failed.', 0, $previous);
    }

    public static function httpFailure(
        string $provider,
        string $model,
        int $status,
        ?string $apiStatus = null,
        ?string $apiMessage = null,
        ?string $credential = null,
        ?int $elapsedMilliseconds = null,
    ): self
    {
        $diagnostic = strtolower(($apiStatus ?? '').' '.($apiMessage ?? ''));
        if ($credential) {
            $diagnostic = str_replace(strtolower($credential), '[redacted]', $diagnostic);
        }

        $reason = match (true) {
            $status === 401, $status === 403 => 'authentication_or_access_denied',
            $status === 404 => 'model_or_endpoint_not_found',
            $status === 429 => 'rate_limited_or_quota_exceeded',
            $status >= 500 => 'provider_unavailable',
            $provider === 'gemini' && preg_match('/api.?key.*(invalid|not valid)|invalid.*api.?key|permission_denied/', $diagnostic) === 1 => 'authentication_or_access_denied',
            preg_match('/resource_exhausted|quota|rate.?limit/', $diagnostic) === 1 => 'rate_limited_or_quota_exceeded',
            preg_match('/not_found|model.{0,80}(not found|unavailable|unsupported)|not found.{0,80}model/', $diagnostic) === 1 => 'model_or_endpoint_not_found',
            default => 'request_rejected_or_model_unsupported',
        };

        $safeApiStatus = self::safeDiagnostic($apiStatus, $credential);
        $safeApiMessage = self::safeDiagnostic($apiMessage, $credential);

        return new self(
            $provider,
            $model,
            $reason,
            $status,
            apiStatus: $safeApiStatus,
            apiMessage: $safeApiMessage,
            elapsedMilliseconds: $elapsedMilliseconds,
        );
    }

    public static function transportFailure(
        string $provider,
        string $model,
        Throwable $error,
        ?string $credential = null,
        ?int $elapsedMilliseconds = null,
        ?int $connectTimeoutSeconds = null,
        ?int $requestTimeoutSeconds = null,
    ): self {
        $message = self::safeDiagnostic($error->getMessage(), $credential) ?? '';
        $curlCode = preg_match('/cURL error (\d+)/i', $message, $matches) === 1
            ? (int) $matches[1]
            : null;
        $type = match ($curlCode) {
            6 => 'dns_failure',
            7 => 'connection_refused',
            28 => preg_match('/failed to connect|connection timed out/i', $message) === 1
                ? 'connection_timeout'
                : 'request_timeout',
            35 => 'tls_error',
            60 => 'certificate_error',
            null => 'network_error',
            default => 'curl_error',
        };
        $technicalDetail = $curlCode === null
            ? __('The HTTP client could not establish a response.')
            : __('cURL error :code: :detail', [
                'code' => $curlCode,
                'detail' => self::curlDetail($message),
            ]);
        $timeoutDetails = [];
        if ($connectTimeoutSeconds !== null) {
            $timeoutDetails[] = __('Connect timeout: :seconds s', ['seconds' => $connectTimeoutSeconds]);
        }
        if ($requestTimeoutSeconds !== null) {
            $timeoutDetails[] = __('Request timeout: :seconds s', ['seconds' => $requestTimeoutSeconds]);
        }
        if ($elapsedMilliseconds !== null) {
            $timeoutDetails[] = __('Elapsed: :milliseconds ms', ['milliseconds' => $elapsedMilliseconds]);
        }
        if ($timeoutDetails !== []) {
            $technicalDetail .= ' '.implode('; ', $timeoutDetails);
        }

        return new self(
            $provider,
            $model,
            'transport_failure',
            previous: $error,
            transportType: $type,
            host: $provider === 'gemini' ? 'generativelanguage.googleapis.com' : null,
            port: $provider === 'gemini' ? 443 : null,
            technicalDetail: $technicalDetail,
            elapsedMilliseconds: $elapsedMilliseconds,
        );
    }

    public function diagnosticMessage(): string
    {
        $reason = match ($this->reason) {
            'credential_missing', 'authentication_or_access_denied' => __('The API key is missing or invalid, or API access is denied.'),
            'model_or_endpoint_not_found' => __('The model or API endpoint was not found.'),
            'rate_limited_or_quota_exceeded' => __('The provider quota or rate limit was reached.'),
            'provider_unavailable' => __('The provider is temporarily unavailable.'),
            'transport_failure' => __('Could not connect to the provider API. Check server network access.'),
            'invalid_response' => __('The provider returned an unexpected response.'),
            'invalid_tool_arguments' => __('The provider returned invalid tool arguments.'),
            'request_failure' => __('The provider request could not be completed.'),
            default => __('The provider rejected the request. Check the model and API configuration.'),
        };
        $provider = match ($this->provider) {
            'gemini' => 'Gemini',
            'openai' => 'OpenAI',
            'ollama' => 'Ollama',
            default => __('AI provider'),
        };
        $message = __('Connection test failed for :provider: :reason', [
            'provider' => $provider,
            'reason' => $reason,
        ]);

        if ($this->apiStatus || $this->apiMessage) {
            $message .= ' '.__('Provider error: :status :details', [
                'status' => $this->apiStatus ?? __('Unknown status'),
                'details' => $this->apiMessage ?? '',
            ]);
        }

        if ($this->transportType) {
            $message .= ' '.__('Error type: :type. Host: :host. Port: :port. :details', [
                'type' => $this->transportType,
                'host' => $this->host ?? __('unknown'),
                'port' => $this->port ?? __('unknown'),
                'details' => $this->technicalDetail ?? '',
            ]);
        }

        return $this->status
            ? __(':message (HTTP :status)', ['message' => $message, 'status' => $this->status])
            : $message;
    }

    private static function safeDiagnostic(?string $message, ?string $credential): ?string
    {
        if ($message === null || $message === '') {
            return null;
        }

        if ($credential) {
            $message = str_ireplace([$credential, rawurlencode($credential)], '[redacted]', $message);
        }

        $message = preg_replace('/AIza[0-9A-Za-z_-]{20,}/', '[redacted]', $message) ?? '';
        $message = preg_replace('/https?:\/\/\S+/i', '[URL redacted]', $message) ?? '';
        $message = preg_replace('/[\r\n\t]+/', ' ', $message) ?? '';

        return mb_substr(trim($message), 0, 400);
    }

    private static function curlDetail(string $message): string
    {
        if (preg_match('/cURL error \d+:\s*(.*?)(?:\s+\(see https?:\/\/|\s+for https?:\/\/|$)/i', $message, $matches) !== 1) {
            return __('No additional cURL detail.');
        }

        return self::safeDiagnostic($matches[1], null) ?? __('No additional cURL detail.');
    }
}
