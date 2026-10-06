<?php

namespace App\Services;

use App\Models\Payment;

/**
 * QRIS.PW behind the PaymentGateway contract.
 *
 * A deliberate thin shell: QrisPwClient keeps every byte of its existing behaviour
 * (credential guards, amount floor, transport classification, `qrispw.*` log keys,
 * raw response passthrough) untouched, and this class only builds the exact payload
 * PaymentService used to build inline. That is what makes the abstraction safe to
 * ship — the QRIS.PW path is behaviourally identical before and after the refactor.
 */
class QrisPwGateway implements PaymentGateway
{
    public function __construct(private readonly QrisPwClient $client) {}

    public function name(): string
    {
        return 'qrispw';
    }

    public function label(): string
    {
        return 'QRIS.PW';
    }

    public function isConfigured(): bool
    {
        foreach (['services.qrispw.api_key', 'services.qrispw.api_secret'] as $key) {
            $value = trim((string) config($key));

            if ($value === '') {
                return false;
            }

            foreach (['your', 'changeme', 'xxx', 'placeholder', 'example', 'todo'] as $marker) {
                if (str_contains(strtolower($value), $marker)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function createPayment(Payment $payment, array $context = []): array
    {
        $tenant = $payment->tenant;

        return $this->client->createPayment([
            'amount' => (int) $payment->amount,
            'order_id' => $payment->order_id,
            'customer_name' => $payment->user?->name ?? $tenant->name,
            'customer_phone' => $tenant->settings['billing_phone'] ?? '081000000000',
            'callback_url' => route('webhooks.qris'),
        ], $context);
    }

    public function checkStatus(Payment $payment, array $context = []): array
    {
        // Raw response already carries status / amount / transaction_id in the
        // vocabulary reconcile matches on; passed through untouched.
        return $this->client->checkStatus((string) $payment->provider_transaction_id, $context);
    }
}