<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

/**
 * Raised when the OpenRouter /models catalog cannot be loaded or is malformed.
 *
 * The message shown to the operator is always a fixed, safe sentence (safeMessage());
 * transport detail stays in the server log via the `previous` throwable only.
 */
class OpenRouterModelException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct('OpenRouter model catalog could not be loaded.', 0, $previous);
    }

    public function safeMessage(): string
    {
        return match ($this->reason) {
            'credential_missing' => __('An OpenRouter API key is required to list models.'),
            'authentication_or_access_denied' => __('The OpenRouter API key is missing or invalid, or API access is denied.'),
            'rate_limited_or_quota_exceeded' => __('OpenRouter rate limit was reached. Try again in a moment.'),
            'provider_unavailable' => __('OpenRouter is temporarily unavailable. Try again shortly.'),
            default => __('The OpenRouter model list could not be refreshed right now.'),
        };
    }
}
