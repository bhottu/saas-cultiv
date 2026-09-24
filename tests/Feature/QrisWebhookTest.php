<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QrisWebhookTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;
    private Plan $pro;
    private Payment $payment;
    private string $secret = 'whsec_test_123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.qrispw.webhook_secret' => $this->secret]);

        $this->pro = Plan::factory()->create([
            'name' => 'Pro', 'slug' => 'pro',
            'price_monthly' => 149000, 'price_yearly' => 1490000,
            'entitlements' => ['max_users' => 10, 'max_api_calls' => 50000],
        ]);

        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'owner_id' => $this->owner->id]);
        $this->tenant->users()->attach($this->owner->id, ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]);

        $invoice = Invoice::create([
            'tenant_id' => $this->tenant->id,
            'invoice_number' => 'INV-2026-000001',
            'amount' => 149000, 'currency' => 'IDR', 'status' => 'open',
            'metadata' => ['plan_id' => $this->pro->id, 'billing_cycle' => 'monthly'],
        ]);
        $this->payment = Payment::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'invoice_id' => $invoice->id,
            'order_id' => 'ORD-TEST-1', 'amount' => 149000, 'currency' => 'IDR', 'status' => 'pending',
            'provider_transaction_id' => 'TRX-1', 'expires_at' => now()->addMinutes(10),
        ]);
    }

    private function signedPayload(array $overrides = []): array
    {
        $payload = array_merge([
            'transaction_id' => 'TRX-1',
            'order_id' => 'ORD-TEST-1',
            'amount' => 149000,
            'status' => 'paid',
            'paid_at' => now()->toDateTimeString(),
            'timestamp' => time(),
        ], $overrides);

        $payload['signature'] = hash_hmac('sha256', json_encode($payload), $this->secret);

        return $payload;
    }

    public function test_valid_webhook_activates_subscription_once(): void
    {
        $this->postJson('/api/webhooks/qris', $this->signedPayload())->assertOk();

        $this->assertDatabaseHas('payments', ['order_id' => 'ORD-TEST-1', 'status' => 'paid']);
        $this->assertDatabaseHas('invoices', ['invoice_number' => 'INV-2026-000001', 'status' => 'paid']);

        $sub = Subscription::where('tenant_id', $this->tenant->id)->where('status', 'active')->first();
        $this->assertNotNull($sub, 'Subscription must be activated after verified payment.');
        $this->assertEquals($this->pro->id, $sub->plan_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.paid']);
    }

    public function test_webhook_is_idempotent_across_replays(): void
    {
        $this->postJson('/api/webhooks/qris', $this->signedPayload())->assertOk();
        $this->postJson('/api/webhooks/qris', $this->signedPayload())->assertOk();
        $this->postJson('/api/webhooks/qris', $this->signedPayload())->assertOk();

        $this->assertSame(1, Subscription::where('tenant_id', $this->tenant->id)->where('status', 'active')->count());
        $this->assertSame(1, Invoice::where('invoice_number', 'INV-2026-000001')->where('status', 'paid')->count());
        $this->assertSame(1, \App\Models\WebhookEvent::where('status', 'processed')->count());
        $this->assertSame(1, \App\Models\WebhookEvent::count(), 'Same fingerprint recorded once — replays deduplicated.');
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $payload = $this->signedPayload();
        $payload['signature'] = strrev($payload['signature']);

        $this->postJson('/api/webhooks/qris', $payload)->assertStatus(403);

        $this->assertDatabaseHas('payments', ['order_id' => 'ORD-TEST-1', 'status' => 'pending']);
        $this->assertDatabaseHas('webhook_events', ['signature_valid' => false, 'status' => 'rejected']);
    }

    public function test_webhook_rejects_amount_mismatch(): void
    {
        $this->postJson('/api/webhooks/qris', $this->signedPayload(['amount' => 1]))->assertOk();

        $this->assertDatabaseHas('payments', ['order_id' => 'ORD-TEST-1', 'status' => 'pending']);
        $this->assertDatabaseHas('webhook_events', ['status' => 'rejected', 'note' => 'amount mismatch']);
    }

    public function test_webhook_ignores_unknown_order(): void
    {
        $this->postJson('/api/webhooks/qris', $this->signedPayload(['order_id' => 'ORD-GHOST']))->assertStatus(404);
    }

    public function test_expired_webhook_does_not_activate(): void
    {
        $this->postJson('/api/webhooks/qris', $this->signedPayload(['status' => 'expired']))->assertOk();

        $this->assertDatabaseHas('payments', ['order_id' => 'ORD-TEST-1', 'status' => 'expired']);
        $this->assertSame(0, Subscription::where('tenant_id', $this->tenant->id)->where('status', 'active')->count());
    }

    public function test_status_reconciliation_marks_paid(): void
    {
        Http::fake([
            '*check-payment.php*' => Http::response([
                'success' => true, 'transaction_id' => 'TRX-1', 'order_id' => 'ORD-TEST-1',
                'amount' => 149000, 'status' => 'paid', 'paid_at' => now()->toDateTimeString(),
            ]),
        ]);

        $status = app(\App\Services\PaymentService::class)->reconcile($this->payment->fresh());

        $this->assertSame('paid', $status);
        $this->assertDatabaseHas('payments', ['order_id' => 'ORD-TEST-1', 'status' => 'paid']);
        $this->assertSame(1, Subscription::where('tenant_id', $this->tenant->id)->where('status', 'active')->count());
    }
}

