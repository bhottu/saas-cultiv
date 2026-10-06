<?php

namespace App\Services;

use App\Models\Payment;

/**
 * One payment provider behind one contract.
 *
 * PaymentService never branches on a provider name: it asks the manager for the
 * gateway stored on the payment row (`payments.provider`) and calls these two verbs.
 * That column is set when the checkout is created, so a payment in flight keeps
 * resolving to the gateway that created it even if the administrator switches the
 * platform over mid-flight.
 *
 * Two rules every implementation must keep:
 *  - createPayment() returns the provider's response with `transaction_id` and
 *    (when the provider sent one) `expires_at` readable at the top level, because
 *    PaymentService stores the array verbatim as payload['create'] and reads those
 *    two keys back. Nothing else may be invented: what the provider answered is
 *    what gets stored.
 *  - checkStatus() answers in THIS platform's vocabulary (paid / failed / expired /
 *    cancelled / pending) so the single reconcile transaction can match on it.
 */
interface PaymentGateway
{
    /** Stable key stored in payments.provider and in payment_settings.active_gateway. */
    public function name(): string;

    /** Human label for the admin gateway picker. */
    public function label(): string;

    /** Are credentials present right now (no network call)? Drives the admin picker. */
    public function isConfigured(): bool;

    /**
     * Create the provider-side payment for this row.
     *
     * @param  array<string, mixed>  $context  correlation ids for the operator log; never a credential
     * @return array<string, mixed> provider response (+ normalized transaction_id)
     *
     * @throws \App\Exceptions\PaymentProviderException
     */
    public function createPayment(Payment $payment, array $context = []): array;

    /**
     * Server-side status fallback for reconcile.
     *
     * @param  array<string, mixed>  $context  correlation ids for the operator log
     * @return array{status: string, amount: int|string|null, transaction_id: string|null}
     *
     * @throws \App\Exceptions\PaymentProviderException
     */
    public function checkStatus(Payment $payment, array $context = []): array;
}