<?php

namespace App\Services;

use App\Models\Payment;

/**
 * Kasera Pay behind the PaymentGateway contract.
 *
 * Two translations happen here, and only two:
 *
 *  1. Payload. Kasera wants external_id / description / customer.name / amount (IDR,
 *     whole rupiah) — the payment row already holds all of it, so the invoice number
 *     travels as merchant_ref for reconciliation on their side too. `payment_methods:
 *     ["qris"]` mints the QR at create time, so the pay page keeps showing the same
 *     kind of artifact it always did, while `checkout_url` in the answer gives a
 *     hosted-page fallback when the buyer is on a desktop.
 *
 *  2. Status. Kasera says pending / succeeded / expired / canceled / failed; this
 *     platform says pending / paid / expired / cancelled / failed. checkStatus()
 *     maps them so the ONE reconcile transaction keeps matching on its own
 *     vocabulary, whatever gateway created the payment.
 *
 * `expires_in_minutes` is deliberately NOT sent: the default provider window is
 * longer than this platform's own 10-minute window, PaymentService takes whichever
 * expiry is sooner, and a payment that still settles after our window closed is
 * handled by the webhook's money-wins rule rather than by a provider-side
 * countdown we cannot control.
 */
class KaseraGateway implements PaymentGateway
{
    public function __construct(private readonly KaseraClient $client) {}

    public function name(): string
    {
        return 'kasera';
    }

    public function label(): string
    {
        return KaseraClient::PROVIDER;
    }

    public function isConfigured(): bool
    {
        $value = trim((string) config('services.kasera.api_key'));

        if ($value === '') {
            return false;
        }

        foreach (['your', 'changeme', 'xxx', 'placeholder', 'example', 'todo'] as $marker) {
            if (str_contains(strtolower($value), $marker)) {
                return false;
            }
        }

        return true;
    }

    public function createPayment(Payment $payment, array $context = []): array
    {
        $tenant = $payment->tenant;

        $payload = [
            'amount' => (int) $payment->amount,
            'description' => mb_substr(
                (string) ($payment->invoice?->description ?? "{$tenant->name} subscription"),
                0,
                255
            ),
            'external_id' => $payment->order_id,
            'merchant_ref' => $payment->invoice?->invoice_number,
            'customer' => [
                'name' => mb_substr($payment->user?->name ?? $tenant->name, 0, 120),
            ],
            'payment_methods' => ['qris'],
        ];

        // The hosted page sends the buyer back here after paying — but only over
        // https: a live key refuses http return URLs outright, and a checkout must
        // never fail on a URL we could simply have omitted. Local/test setups skip
        // the key; the pay page polls regardless of any redirect.
        $returnUrl = route('billing.pay', $payment);

        if (str_starts_with($returnUrl, 'https://')) {
            $payload['return_url'] = $returnUrl;
        }

        $data = $this->client->createPayment($payload, $payment->order_id, $context);

        // Normalize for PaymentService, which reads `transaction_id` at the top level
        // and stores the whole array verbatim as payload['create']. `id` (payreq_…)
        // IS the transaction id — the key is only aliased, never rewritten, and the
        // QR string is copied up from payment.qr_string untouched so the pay page's
        // existing QR display works exactly as it does for QRIS.PW.
        $data['transaction_id'] = $data['id'] ?? null;

        $qrString = $data['payment']['qr_string'] ?? null;

        if (is_string($qrString) && trim($qrString) !== '' && empty($data['qr_string'])) {
            $data['qr_string'] = $qrString;
        }

        return $data;
    }

    public function checkStatus(Payment $payment, array $context = []): array
    {
        $raw = $this->client->checkStatus((string) $payment->provider_transaction_id, $context);

        $status = strtolower((string) ($raw['status'] ?? 'pending'));

        return [
            // Kasera's vocabulary translated to this platform's.
            'status' => match ($status) {
                'succeeded', 'paid', 'success', 'settlement' => 'paid',
                'canceled', 'cancelled' => 'cancelled',
                'failed' => 'failed',
                'expired' => 'expired',
                default => $status,
            },
            'amount' => $raw['amount'] ?? null,
            'transaction_id' => $raw['id'] ?? $payment->provider_transaction_id,
        ];
    }
}