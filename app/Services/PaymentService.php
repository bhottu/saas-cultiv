<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Notifications\SubscriptionActivatedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Payment lifecycle: create QRIS payment → pending → webhook/status-check → paid/expired.
 * A successful API response does NOT mean paid — only verified webhook/status does.
 */
class PaymentService
{
    public function __construct(private readonly QrisPwClient $client) {}

    /** Create invoice + pending QRIS payment for a plan checkout. */
    public function createCheckout(Tenant $tenant, Plan $plan, string $cycle, $user): Payment
    {
        $amount = $plan->priceFor($cycle);

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'invoice_number' => $this->nextInvoiceNumber(),
            'amount' => $amount,
            'currency' => $plan->currency,
            'status' => 'open',
            'description' => "{$plan->name} ({$cycle}) subscription",
            'metadata' => ['plan_id' => $plan->id, 'billing_cycle' => $cycle],
            'due_at' => now()->addHours(1),
        ]);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'provider' => 'qrispw',
            'order_id' => 'ORD-'.strtoupper(Str::random(14)),
            'amount' => $amount,
            'currency' => $plan->currency,
            'status' => 'pending',
        ]);

        $this->dispatchToProvider($payment);

        return $payment->refresh();
    }

    /** Call QRIS.PW create-payment and store the QR + expiration. */
    public function dispatchToProvider(Payment $payment): void
    {
        $tenant = $payment->tenant;

        $resp = $this->client->createPayment([
            'amount' => (int) $payment->amount,
            'order_id' => $payment->order_id,
            'customer_name' => $payment->user?->name ?? $tenant->name,
            'customer_phone' => $tenant->settings['billing_phone'] ?? '081000000000',
            'callback_url' => route('webhooks.qris'),
        ]);

        $payment->update([
            'provider_transaction_id' => $resp['transaction_id'] ?? null,
            'expires_at' => isset($resp['expires_at']) ? \Illuminate\Support\Carbon::parse($resp['expires_at']) : now()->addMinutes(10),
            'payload' => ['create' => $resp],
        ]);

        AuditLogger::log('payment.created', $payment, ['amount' => $payment->amount]);
    }

    /**
     * Server-side status fallback. Only trust verified provider responses.
     * Reuses the same idempotent transactional path as the webhook.
     */
    public function reconcile(Payment $payment): string
    {
        if (! $payment->provider_transaction_id) {
            return $payment->status;
        }

        if ($payment->status === 'pending' && $payment->isExpired()) {
            $this->markExpired($payment);
            return 'expired';
        }

        if ($payment->status !== 'pending') {
            return $payment->status;
        }

        $data = $this->client->checkStatus($payment->provider_transaction_id);
        $status = strtolower((string) ($data['status'] ?? 'pending'));

        return DB::transaction(function () use ($payment, $data, $status) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($payment->status !== 'pending') {
                return $payment->status;
            }

            if ((int) ($data['amount'] ?? 0) !== (int) $payment->amount) {
                Log::error('qrispw.status.amount_mismatch', ['order_id' => $payment->order_id]);
                return $payment->status;
            }

            $payload = $payment->payload ?? [];

            match ($status) {
                'paid', 'success', 'settlement' => app(WebhookService::class)->markPaidFromStatus($payment, $data),
                'failed' => $payment->update(['status' => 'failed', 'payload' => $payload + ['check' => $data]]),
                'expired' => $payment->update(['status' => 'expired', 'payload' => $payload + ['check' => $data]]),
                default => $payment->update(['payload' => $payload + ['check' => $data]]),
            };

            return $payment->refresh()->status;
        });
    }

    public function markExpired(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $p = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($p->status === 'pending') {
                $p->update(['status' => 'expired']);
                $p->invoice?->update(['status' => 'cancelled']);
                AuditLogger::log('payment.expired', $p);
            }
        });
    }

    /** Portable atomic numbering: upsert year row, lock, increment. */
    private function nextInvoiceNumber(): string
    {
        return DB::transaction(function () {
            $year = now()->year;

            DB::table('invoice_sequences')->upsert(['year' => $year, 'last_number' => 0], ['year'], []);

            $current = (int) DB::table('invoice_sequences')->where('year', $year)->lockForUpdate()->value('last_number');
            $next = $current + 1;

            DB::table('invoice_sequences')->where('year', $year)->update(['last_number' => $next]);

            return sprintf('INV-%d-%06d', $year, $next);
        });
    }
}
