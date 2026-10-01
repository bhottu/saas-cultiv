<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The columns the billing flow relies on.
 *
 * The pending-payment flow reads and writes status, expires_at and the provider
 * reference, and "cancel & create new" needs cancelled_at to record when the old
 * order was settled. If one of these were missing the flow would fail at runtime on
 * production only, so the schema is asserted here instead.
 */
class PaymentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_payments_table_carries_every_column_the_flow_uses(): void
    {
        foreach ([
            'status',
            'expires_at',
            'cancelled_at',
            'provider_transaction_id',
            'order_id',
            'invoice_id',
            'amount',
            'payload',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('payments', $column),
                "payments.{$column} is missing; the pending-payment flow cannot run without it."
            );
        }
    }

    public function test_invoices_table_carries_the_status_and_number_the_flow_uses(): void
    {
        foreach (['status', 'invoice_number', 'amount'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('invoices', $column),
                "invoices.{$column} is missing."
            );
        }
    }

    /** The four states the report requires must all be reachable values. */
    public function test_the_status_vocabulary_covers_pending_paid_expired_and_cancelled(): void
    {
        $settled = \App\Services\PaymentService::SETTLED_STATUSES;
        $unsettled = \App\Services\PaymentService::UNSETTLED_STATUSES;

        $this->assertContains('pending', $unsettled);
        $this->assertContains('expired', $unsettled);
        $this->assertContains('paid', $settled);
        $this->assertContains('completed', $settled);

        // `cancelled` is deliberately neither: it is a terminal decision, but it is not
        // a success either, so it must never be treated as payable.
        $this->assertNotContains('cancelled', $settled);
        $this->assertNotContains('cancelled', $unsettled);
    }
}