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

    public static function fromProviderResponse(int $status, string $operation): self
    {
        $e = new self("QRIS.PW {$operation} failed (HTTP {$status}).");
        $e->providerStatus = $status;

        return $e;
    }
}