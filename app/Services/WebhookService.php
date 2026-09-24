<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\SubscriptionActivatedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Handles the QRIS.PW webhook end-to-end: verify → validate → idempotent process. */
class WebhookService
{
    public function __construct(private readonly QrisPwClient $client) {}

    public function handle(array $payload, string $rawBody): array
    {
        // 1. Signature verification (HMAC-SHA256 with webhook secret).
        //    Provider signs the payload BEFORE appending the signature field, so verify
        //    against both the raw body and the canonical re-encode without 'signature'.
        $secret = config('services.qrispw.webhook_secret');
        $signature = (string) ($payload['signature'] ?? '');
        $dataWithoutSig = $payload;
        unset($dataWithoutSig['signature']);

        $valid = $secret !== '' && $signature !== '' && (
            hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)
            || hash_equals(hash_hmac('sha256', json_encode($dataWithoutSig), $secret), $signature)
        );

        $txnId = (string) ($payload['transaction_id'] ?? '');
        $orderId = (string) ($payload['order_id'] ?? '');
        $status = strtolower((string) ($payload['status'] ?? ''));

        // 2. Idempotency anchor: provider+transaction+status is processed at most once.
        $event = WebhookEvent::firstOrCreate(
            ['event_id' => WebhookEvent::fingerprint('qrispw', $txnId, $status)],
            [
                'provider' => 'qrispw',
                'transaction_id' => $txnId,
                'order_id' => $orderId,
                'signature_valid' => $valid,
                'payload' => $payload,
                'status' => 'received',
            ]
        );

        if (! $valid) {
            $event->update(['status' => 'rejected', 'note' => 'invalid signature']);
            Log::warning('qrispw.webhook.rejected', ['order_id' => $orderId]);
            abort(403, 'Invalid webhook signature.');
        }

        if (! $event->wasRecentlyCreated && $event->status === 'processed') {
            return ['status' => 'duplicate', 'event_id' => $event->id]; // ack, no-op
        }

        // 3. Locate the payment; verify expected record.
        $payment = Payment::where('order_id', $orderId)->first();

        if (! $payment) {
            $event->update(['status' => 'ignored', 'note' => 'unknown order_id']);
            abort(404, 'Unknown order.');
        }

        if ($payment->provider_transaction_id && $txnId && $payment->provider_transaction_id !== $txnId) {
            $event->update(['status' => 'rejected', 'note' => 'transaction id mismatch']);
            abort(422, 'Transaction mismatch.');
        }

        try {
            DB::transaction(function () use ($payment, $payload, $status, $event, $txnId) {
                // Lock the payment row to serialize concurrent deliveries.
                $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

                if ($payment->status !== 'pending') {
                    return; // already terminal — idempotent no-op
                }

                // Amount must match what we invoiced.
                if ((int) ($payload['amount'] ?? 0) !== (int) $payment->amount) {
                    $event->update(['status' => 'rejected', 'note' => 'amount mismatch']);
                    Log::error('qrispw.webhook.amount_mismatch', ['order_id' => $payment->order_id]);
                    return;
                }

                match ($status) {
                    'paid', 'success', 'settlement' => $this->markPaid($payment, $txnId, $payload),
                    'failed' => $this->markTerminal($payment, 'failed', $payload),
                    'expired' => $this->markTerminal($payment, 'expired', $payload),
                    'cancelled' => $this->markTerminal($payment, 'cancelled', $payload),
                    default => null, // pending/unknown → ignore
                };

                $event->update(['status' => 'processed', 'processed_at' => now()]);
            });

            return ['status' => 'ok', 'payment' => $payment->refresh()->status];
        } catch (\Throwable $e) {
            $event->update(['status' => 'ignored', 'note' => substr($e->getMessage(), 0, 200)]);
            Log::error('qrispw.webhook.error', ['error' => $e->getMessage(), 'order_id' => $orderId]);
            throw $e;
        }
    }

    private function markPaid(Payment $payment, string $txnId, array $payload): void
    {
        $payment->update([
            'status' => 'paid',
            'provider_transaction_id' => $txnId ?: $payment->provider_transaction_id,
            'paid_at' => $payload['paid_at'] ?? now(),
            'payload' => array_merge($payment->payload ?? [], ['webhook' => $payload]),
        ]);

        AuditLogger::log('payment.paid', $payment, ['amount' => $payment->amount]);

        // Invoice + subscription activation in the same transaction.
        if ($payment->invoice) {
            $payment->invoice->markPaid();
            app(SubscriptionService::class)->activateFromInvoice($payment->invoice);
        }

        if ($payment->user) {
            $payment->user->notify(new SubscriptionActivatedNotification($payment));
        }
    }

    /** Used by the server-side status reconciliation path. */
    public function markPaidFromStatus(Payment $payment, array $providerData): void
    {
        $this->markPaid(
            $payment,
            (string) ($providerData['transaction_id'] ?? $payment->provider_transaction_id),
            ['check' => $providerData]
        );
    }

    private function markTerminal(Payment $payment, string $status, array $payload): void
    {
        $payment->update([
            'status' => $status,
            'payload' => array_merge($payment->payload ?? [], ['webhook' => $payload]),
        ]);

        AuditLogger::log("payment.{$status}", $payment);

        if ($payment->user) {
            $payment->user->notify(new PaymentFailedNotification($payment, $status));
        }
    }
}
