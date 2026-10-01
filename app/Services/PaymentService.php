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
    /** Terminal states: a payment that reached one of these can never be cancelled. */
    public const SETTLED_STATUSES = ['paid', 'completed', 'successful', 'settlement'];

    /** States that may still be abandoned by the customer. */
    public const UNSETTLED_STATUSES = ['pending', 'expired'];

    /**
     * How long an unsettled payment stays payable.
     *
     * Ten minutes: long enough to open a banking app and pay, short enough that a
     * customer who wandered off does not come back to a prompt for a dead QR.
     */
    public const PAYMENT_WINDOW_MINUTES = 10;

    public function __construct(
        private readonly QrisPwClient $client,
    ) {}

    /**
     * The one payment that currently blocks a new checkout for this workspace, if any.
     *
     * Scoped to the tenant on purpose — another workspace's payment must never be seen
     * here, and the BelongsToTenant global scope backs that up. Status AND expiration
     * both matter: a row still saying "pending" whose window has closed is not a live
     * obligation, so stale rows are settled to "expired" first and the caller always
     * gets an honest, up-to-date answer.
     */
    public function activePending(Tenant $tenant): ?Payment
    {
        $stale = $tenant->payments()->where('status', 'pending')->get();

        foreach ($stale as $payment) {
            if ($payment->isExpired()) {
                $this->markExpired($payment);
            }
        }

        return $tenant->payments()
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first();
    }

    /**
     * Abandon an unsettled payment at the customer's request.
     *
     * The record is NEVER deleted: status becomes "cancelled", cancelled_at records when,
     * the invoice is closed and the audit log keeps the trail. A settled payment is
     * refused outright, and the provider is never told the payment succeeded — this only
     * says the customer walked away from a QR they did not scan.
     */
    public function cancelUnsettled(Payment $payment, ?object $actor = null): bool
    {
        return DB::transaction(function () use ($payment, $actor) {
            // Re-read under a row lock: the webhook may have settled this payment in the
            // seconds between the click and here.
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if (! $locked || ! in_array($locked->status, self::UNSETTLED_STATUSES, true)) {
                return false;
            }

            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'payload' => array_merge($locked->payload ?? [], [
                    'cancelled' => ['at' => now()->toIso8601String(), 'by_user_id' => $actor?->id],
                ]),
            ]);

            $locked->invoice?->update(['status' => 'cancelled']);

            AuditLogger::log('payment.cancelled', $locked, [
                'amount' => $locked->amount,
                'was_status' => $payment->status,
                'actor_user_id' => $actor?->id,
            ]);

            return true;
        });
    }

    /** Create invoice + pending QRIS payment for a plan checkout. */
    public function createCheckout(Tenant $tenant, Plan $plan, string $cycle, $user): Payment
    {
        if ($cycle === 'yearly' && $plan->price_yearly <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'cycle' => 'Yearly billing is not available yet. Please choose monthly billing.',
            ]);
        }

        $amount = $plan->priceFor($cycle);

        // The invoice, the payment and the provider hand-off have to succeed or leave
        // nothing behind. Previously a gateway refusal (401 with no API key, timeout,
        // rate limit) blew up after the rows were already written, so every failed
        // attempt left an open invoice and a pending payment with no QR, and the
        // pending-payment notice then pointed at a payment nobody could pay.
        return DB::transaction(function () use ($tenant, $plan, $cycle, $user, $amount) {
            // Serialise concurrent checkouts for this workspace. Two rapid Subscribe
            // clicks are two separate HTTP requests, so the client-side button state
            // cannot be trusted; locking the tenant row makes the "is there already an
            // active payment?" check and the insert below one indivisible unit. The
            // second request then sees the first one's payment and is sent to the
            // confirmation modal instead of creating a duplicate.
            Tenant::whereKey($tenant->id)->lockForUpdate()->first();

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
        });
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
        ], [
            // Correlation ids for the operator. Ids only — no credential is ever
            // passed into the gateway client or written to the log.
            'tenant_id' => $payment->tenant_id,
            'user_id' => $payment->user_id,
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'order_id' => $payment->order_id,
            // The plan is what an operator needs when an amount is refused: the price
            // lives on plans, so this points straight at the row to inspect.
            'plan_id' => $payment->invoice?->metadata['plan_id'] ?? null,
            'amount' => (int) $payment->amount,
        ]);

        // A pending payment is payable for exactly PAYMENT_WINDOW_MINUTES from the moment
        // it was created. That window is ours, not the provider's: taking the provider's
        // expires_at verbatim meant the "continue or start over" prompt could stay open for
        // a QR the provider had already expired, which is exactly the dead end this flow
        // exists to prevent. If the provider says something sooner, we honour the sooner
        // value so we never promise a window the gateway has already closed.
        $expiresAt = $payment->created_at->copy()->addMinutes(self::PAYMENT_WINDOW_MINUTES);

        if (isset($resp['expires_at'])) {
            try {
                $providerExpiry = \Illuminate\Support\Carbon::parse($resp['expires_at']);

                if ($providerExpiry->isBefore($expiresAt)) {
                    $expiresAt = $providerExpiry;
                }
            } catch (\Throwable $e) {
                // An unparseable provider value must not cost the customer their window;
                // our own value is already correct.
                report($e);
            }
        }

        $payment->update([
            'provider_transaction_id' => $resp['transaction_id'] ?? null,
            'expires_at' => $expiresAt,
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

    /**
     * Portable atomic numbering: make sure the year row exists, lock it, increment.
     *
     * The row must be created with insertOrIgnore, NOT upsert. Two reasons, both learned
     * the hard way on the live site:
     *
     *  1. `upsert($values, ['year'], [])` — an empty update list — makes Laravel's
     *     Builder short-circuit to a plain INSERT and drop the conflict target, so the
     *     second checkout of a year died on `invoice_sequences_pkey` (HTTP 500).
     *  2. `upsert($values, ['year'], ['last_number'])` compiles to
     *     `ON CONFLICT (year) DO UPDATE SET last_number = excluded.last_number`, and
     *     `excluded` here is the 0 we were inserting — so the counter was reset to 0 on
     *     every checkout and the SAME invoice number was handed out twice.
     *
     * `insertOrIgnore` is `ON CONFLICT DO NOTHING`: it seeds the counter once and never
     * rewinds it, so both the crash and the duplicate number are gone. The row lock below
     * then makes the read-modify-write atomic between concurrent checkouts.
     */
    private function nextInvoiceNumber(): string
    {
        return DB::transaction(function () {
            $year = now()->year;

            DB::table('invoice_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);

            $current = (int) DB::table('invoice_sequences')->where('year', $year)->lockForUpdate()->value('last_number');
            $next = $current + 1;

            DB::table('invoice_sequences')->where('year', $year)->update(['last_number' => $next]);

            return sprintf('INV-%d-%06d', $year, $next);
        });
    }
}
