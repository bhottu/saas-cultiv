<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The payment gateway could not give us a usable payment.
 *
 * Distinct from a database or programming fault on purpose. A missing API key, a rejected
 * order, a timeout or a rate limit are all conditions the customer can act on by trying
 * again, so the checkout can answer with a retryable message instead of a 500. Anything
 * that is NOT a gateway problem must keep bubbling up as a 500 rather than being
 * disguised as a payment hiccup.
 */
class PaymentProviderException extends RuntimeException
{
    /** Provider HTTP status, when the failure came back over HTTP. */
    public ?int $providerStatus = null;

    /**
     * Which kind of failure this is, so the checkout can tell the customer something
     * true instead of one catch-all sentence.
     *
     *  - auth        the provider rejected our credentials (or we have none)
     *  - request     the provider considered the order itself unacceptable
     *  - rate_limit  the provider is throttling us
     *  - network     we never reached the provider (timeout, DNS, refused, TLS)
     *  - unavailable the provider answered, but is broken on its side
     */
    public string $category = 'unavailable';

    /**
     * Operator context (ids and endpoint) — safe to log, never a credential.
     *
     * @var array<string, mixed>
     */
    public array $context = [];

    public static function fromProviderResponse(int $status, string $operation, array $context = [], string $provider = 'QRIS.PW'): self
    {
        $e = new self("{$provider} {$operation} failed (HTTP {$status}).");
        $e->providerStatus = $status;
        $e->category = match (true) {
            $status === 401, $status === 403 => 'auth',
            $status === 400, $status === 422 => 'request',
            $status === 429 => 'rate_limit',
            default => 'unavailable',
        };
        $e->context = $context + ['endpoint' => $operation];

        return $e;
    }

    /** We could not even talk to the provider — no HTTP status exists for this. */
    public static function transport(string $operation, string $reason, array $context = [], string $provider = 'QRIS.PW'): self
    {
        $e = new self("{$provider} {$operation} could not be reached ({$reason}).");
        $e->category = 'network';
        $e->context = $context + ['endpoint' => $operation, 'reason' => $reason];

        return $e;
    }

    /** The integration is not configured; no request was attempted. */
    public static function notConfigured(string $operation, string $missing, array $context = [], string $provider = 'QRIS.PW'): self
    {
        $e = new self("{$provider} {$operation} is not configured ({$missing} is missing).");
        $e->category = 'configuration';
        $e->context = $context + ['endpoint' => $operation, 'missing' => $missing];

        return $e;
    }

    /**
     * The amount can never be accepted by the gateway.
     *
     * Almost always a mispriced plan (a price edited directly in the database) rather
     * than anything the customer did. Retrying cannot help, so this is reported as a
     * configuration fault instead of a "try again" request error.
     */
    public static function amountBelowMinimum(string $operation, int $amount, int $minimum, array $context = [], string $provider = 'QRIS.PW'): self
    {
        $e = new self(
            "{$provider} {$operation} refused locally: amount {$amount} is below the provider minimum of {$minimum}."
        );
        $e->category = 'configuration';
        $e->context = $context + [
            'endpoint' => $operation,
            'amount' => $amount,
            'provider_minimum' => $minimum,
        ];

        return $e;
    }

    /**
     * The sentence the customer reads.
     *
     * Split by cause on purpose. A credential problem needs an administrator, a
     * rejected order is worth retrying, and an unreachable gateway is temporary —
     * telling a paying customer "we could not reach the provider" about their own
     * valid payment sends them to the wrong place.
     */
    public function userMessage(): string
    {
        return match ($this->category) {
            'auth', 'configuration' => 'Payment configuration error. Please contact the administrator.',
            'request' => 'Unable to create the payment request. Please try again.',
            'rate_limit' => 'The payment service is busy. Please try again in a moment.',
            'network' => 'The payment service is temporarily unavailable. Please try again later.',
            default => 'The payment service is temporarily unavailable. Please try again later.',
        };
    }
}