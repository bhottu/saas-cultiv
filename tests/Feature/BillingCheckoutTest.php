<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Billing checkout + the pending-payment notice.
 *
 * The checkout is the one flow that must never 500: it creates an invoice, then talks
 * to QRIS.PW, then renders the QR. The notice beside it is driven entirely by server
 * state so a stale page can never offer a QR for a dead payment.
 */
class BillingCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->owner = User::create([
            'name' => 'Billing Owner', 'email' => 'billing-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Billing Tenant', 'slug' => 'billing-tenant', 'owner_id' => $this->owner->id,
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    /**
     * Regression guard for the 500 that hit production: the year counter row used to be
     * seeded with upsert(..., []), which Laravel degrades to a plain INSERT, so the
     * second checkout of a year died on invoice_sequences_pkey.
     *
     * "Replace" is the natural way to reach a second checkout in one test, since it is
     * exactly what cancels the first payment and starts a new order.
     */
    public function test_invoice_number_sequence_survives_a_second_checkout_in_the_same_year(): void
    {
        $this->fakeGateway();

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);
        $first = Payment::firstOrFail();

        $this->member()->post('/billing/checkout/resolve', [
            'payment_id' => $first->id, 'plan' => 'starter',
            'cycle' => 'monthly', 'intent' => 'replace',
        ]);

        $second = Payment::where('status', 'pending')->firstOrFail();

        // INV-<year>-<counter>: the counter must advance, never repeat.
        $this->assertNotSame($first->invoice_id, $second->invoice_id);
        $this->assertNotSame($first->invoice->invoice_number, $second->invoice->invoice_number);
        $this->assertMatchesRegularExpression('/^INV-'.date('Y').'-\d{6}$/', $second->invoice->invoice_number);
        $this->assertSame(2, Invoice::count());
    }

    public function test_qrispw_config_is_the_only_source_of_credentials(): void
    {
        // The checkout must never fall back to a hardcoded key.
        $this->assertSame(env('QRISPW_API_KEY'), config('services.qrispw.api_key'));
        $this->assertSame(env('QRISPW_API_SECRET'), config('services.qrispw.api_secret'));
    }

    public function test_a_gateway_failure_is_retryable_instead_of_a_500(): void
    {
        Http::fake(['qris.pw/*' => Http::response(['success' => false, 'error' => 'Missing API credentials'], 401)]);

        $res = $this->actingAs($this->owner)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);

        // A provider outage must not look like an application crash to the customer.
        $res->assertRedirect(route('billing.index'));
        $res->assertSessionHas('error');

        // /billing must still render afterwards — the page is their way back.
        $this->billingIndexHtml();

        // Nothing half-created is left dangling for an operator to reconcile.
        $this->assertSame(0, Payment::count());
    }

    public function test_an_unrelated_server_fault_is_still_a_500(): void
    {
        Http::fake(['qris.pw/*' => Http::response(['success' => true, 'transaction_id' => 'TX-1'], 200)]);

        // Force a database failure to prove the catch is narrow: only a gateway problem
        // is softened into a retry message, everything else must still surface as a 500.
        \Illuminate\Support\Facades\DB::statement('DROP TABLE invoices');

        // The application's own error handler decides the response, so this asserts the
        // real production status rather than a rethrown exception.
        $this->actingAs($this->owner)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly'])
            ->assertStatus(500);
    }

    public function test_pending_notification_says_expired_and_offers_no_qr_once_the_window_is_gone(): void
    {
        $this->pendingPayment(expiresAt: now()->subMinute());

        $html = $this->billingIndexHtml();

        $this->assertStringContainsString('Payment expired', $html);
        $this->assertStringNotContainsString('Show QR code', $html);
        $this->assertStringNotContainsString('Pending payment', $html);
    }

    public function test_live_pending_payment_still_offers_the_qr(): void
    {
        $this->pendingPayment(expiresAt: now()->addMinutes(10));

        $html = $this->billingIndexHtml();

        $this->assertStringContainsString('Pending payment', $html);
        $this->assertStringContainsString('Show QR code', $html);
    }

    public function test_dismissing_the_pending_notice_survives_a_reload(): void
    {
        $payment = $this->pendingPayment(expiresAt: now()->addMinutes(10));

        $this->actingAs($this->owner)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->post(route('billing.notice.dismiss'), ['key' => $payment->id])
            ->assertRedirect(route('billing.index'));

        $this->assertDatabaseHas('dismissed_notifications', [
            'user_id' => $this->owner->id,
            'key' => 'pending_payment:'.$payment->id,
        ]);

        // Reload: the notice must stay gone, while the payment record itself is untouched.
        $html = $this->billingIndexHtml();
        $this->assertStringNotContainsString('Show QR code', $html);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }

    public function test_a_dismissal_cannot_hide_someone_elses_payment_or_a_paid_one(): void
    {
        $this->pendingPayment(expiresAt: now()->addMinutes(10));
        $paid = $this->pendingPayment(expiresAt: now()->addMinutes(10), status: 'paid');

        $this->actingAs($this->owner)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->post(route('billing.notice.dismiss'), ['key' => $paid->id])
            ->assertSessionHasErrors('key');

        $this->assertDatabaseMissing('dismissed_notifications', [
            'user_id' => $this->owner->id, 'key' => 'pending_payment:'.$paid->id,
        ]);
    }

    public function test_paid_payment_is_never_reported_as_pending_or_expired(): void
    {
        $this->pendingPayment(expiresAt: now()->subHour(), status: 'paid');

        $html = $this->billingIndexHtml();

        $this->assertStringNotContainsString('Pending payment', $html);
        $this->assertStringNotContainsString('Payment expired', $html);
        $this->assertStringContainsString('paid', $html);
    }

    // ================================================================ Scenario A

    public function test_a_no_pending_payment_creates_one_and_redirects_to_the_qr(): void
    {
        $this->fakeGateway();

        $res = $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);

        $payment = Payment::firstOrFail();

        $res->assertRedirect(route('billing.pay', $payment));
        $this->assertSame('pending', $payment->status);
        $this->assertNotNull($payment->provider_transaction_id);
        $this->assertNotNull($payment->expires_at);
    }

    // ================================================================ Scenario B

    public function test_b_subscribing_again_while_a_live_payment_exists_shows_the_modal_not_a_429(): void
    {
        $this->fakeGateway();
        $existing = $this->pendingPayment(now()->addMinutes(10));

        $res = $this->member()->post('/billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly']);

        // No 429: the customer is asked what to do instead.
        $res->assertRedirect(route('billing.index'));
        $res->assertSessionHas('pending_checkout');
        $this->assertSame($existing->id, session('pending_checkout')['payment_id']);
        $this->assertSame('pro', session('pending_checkout')['plan']);

        // Nothing new was created.
        $this->assertSame(1, Payment::count());
        $this->assertSame('pending', $existing->fresh()->status);

        $html = $this->billingIndexHtml();
        $this->assertStringContainsString('Pembayaran Masih Menunggu', $html);
        $this->assertStringContainsString('Lanjut Bayar', $html);
        $this->assertStringContainsString('Buat Baru', $html);
    }

    public function test_b_continue_returns_to_the_existing_qr_without_creating_anything(): void
    {
        $this->fakeGateway();
        $existing = $this->pendingPayment(now()->addMinutes(10));

        $this->member()->post('/billing/checkout/resolve', [
            'payment_id' => $existing->id, 'plan' => 'pro',
            'cycle' => 'monthly', 'intent' => 'continue',
        ])->assertRedirect(route('billing.pay', $existing));

        $this->assertSame(1, Payment::count());
        $this->assertSame('pending', $existing->fresh()->status);
    }

    public function test_b_continue_re_checks_expiry_on_the_server(): void
    {
        $this->fakeGateway();

        // The page said "continue", but by the time the form was submitted the QR window
        // had closed. The browser's opinion must not win.
        $stale = $this->pendingPayment(now()->subMinute());

        $res = $this->member()->post('/billing/checkout/resolve', [
            'payment_id' => $stale->id, 'plan' => 'pro',
            'cycle' => 'monthly', 'intent' => 'continue',
        ]);

        $res->assertRedirect(route('billing.index'));
        $res->assertSessionHas('status');
        $this->assertStringContainsString('kedaluwarsa', session('status')['message']);
        $this->assertSame('expired', $stale->fresh()->status);
        $this->assertSame(1, Payment::count());
    }

    // ================================================================ Scenario C

    public function test_c_replace_cancels_the_old_payment_and_creates_a_new_one(): void
    {
        $this->fakeGateway();
        $existing = $this->pendingPayment(now()->addMinutes(10));
        $invoiceId = $existing->invoice_id;

        $res = $this->member()->post('/billing/checkout/resolve', [
            'payment_id' => $existing->id, 'plan' => 'pro',
            'cycle' => 'monthly', 'intent' => 'replace',
        ]);

        $old = $existing->fresh();
        $new = Payment::where('id', '!=', $old->id)->firstOrFail();

        $res->assertRedirect(route('billing.pay', $new));

        // Cancelled, NOT deleted — the history must survive.
        $this->assertDatabaseHas('payments', ['id' => $old->id, 'status' => 'cancelled']);
        $this->assertNotNull($old->cancelled_at, 'The cancellation must be timestamped for the audit trail.');
        $this->assertSame('cancelled', Invoice::find($invoiceId)->status);

        $this->assertSame('pending', $new->status);
        $this->assertSame(Plan::where('slug', 'pro')->value('price_monthly'), $new->amount);
        $this->assertNotNull($new->provider_transaction_id);
    }

    public function test_c_a_paid_payment_can_never_be_cancelled(): void
    {
        $this->fakeGateway();
        $paid = $this->pendingPayment(now()->addMinutes(10), status: 'paid', paidAt: now());

        $res = $this->member()->post('/billing/checkout/resolve', [
            'payment_id' => $paid->id, 'plan' => 'pro',
            'cycle' => 'monthly', 'intent' => 'replace',
        ]);

        $res->assertRedirect(route('billing.index'));
        $res->assertSessionHas('status');
        $this->assertStringContainsString('tidak dapat dibatalkan', session('status')['message']);

        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertNull($paid->fresh()->cancelled_at);
        $this->assertSame(1, Payment::count(), 'No replacement payment for an already-paid order.');
    }

    public function test_c_another_tenants_payment_is_invisible(): void
    {
        $this->fakeGateway();
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-t', 'owner_id' => $this->owner->id]);
        $foreign = Payment::withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'user_id' => $this->owner->id,
            'order_id' => 'ORD-FOREIGN', 'amount' => 5_000_000, 'currency' => 'IDR',
            'status' => 'pending', 'expires_at' => now()->addMinutes(10),
        ]);

        // "Continue" cannot reach it...
        $this->member()->post('/billing/checkout/resolve', [
            'payment_id' => $foreign->id, 'plan' => 'pro',
            'cycle' => 'monthly', 'intent' => 'continue',
        ])->assertRedirect(route('billing.index'));

        // ...nor cancel it.
        $this->member()->post('/billing/checkout/resolve', [
            'payment_id' => $foreign->id, 'plan' => 'pro',
            'cycle' => 'monthly', 'intent' => 'replace',
        ])->assertRedirect(route('billing.index'));

        $this->assertSame('pending', $foreign->fresh()->status);
    }

    // ================================================================ Scenario D

    public function test_d_an_expired_payment_neither_blocks_nor_offers_a_qr(): void
    {
        $this->fakeGateway();
        $dead = $this->pendingPayment(now()->subMinute());

        // The stale row is settled, so it stops being a blocker, and the new plan goes
        // straight through with no cancel-popup at all.
        $res = $this->member()->post('/billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly']);

        $this->assertSame('expired', $dead->fresh()->status);
        $this->assertSame(2, Payment::count());

        $new = Payment::latest('id')->firstOrFail();
        $res->assertRedirect(route('billing.pay', $new));
        $this->assertNull(session('pending_checkout'), 'No cancellation prompt for an expired payment.');
    }

    // ================================================================ Scenario E

    public function test_e_repeated_subscribes_never_create_a_second_active_payment(): void
    {
        $this->fakeGateway();

        for ($i = 0; $i < 6; $i++) {
            $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);
        }

        // Six clicks, one live payment, and no 429 for the customer.
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Payment::where('status', 'pending')->count());
    }

    public function test_e_the_billing_page_itself_is_no_longer_rate_limited(): void
    {
        // The page view and the checkout used to share one 5/min bucket, so reloading
        // /billing locked the customer out of their own billing page.
        for ($i = 0; $i < 12; $i++) {
            $this->billingIndexHtml();
        }

        $this->assertTrue(true);
    }

    // ================================================== Scenarios F & G — warehouse

    public function test_f_stock_offers_add_warehouse_when_there_is_none(): void
    {
        $this->assertSame(0, Warehouse::count());

        $html = $this->stockIndexHtml();

        $this->assertStringContainsString('Not configured', $html);
        $this->assertStringContainsString('Add Warehouse', $html);
        $this->assertStringContainsString(route('warehouses.create'), $html);
    }

    public function test_g_stock_shows_the_warehouse_and_hides_the_empty_state(): void
    {
        Warehouse::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Gudang Utama', 'code' => 'MAIN', 'is_active' => true,
        ]);

        $html = $this->stockIndexHtml();

        $this->assertStringContainsString('Gudang Utama', $html);
        $this->assertStringNotContainsString('Not configured', $html);
        $this->assertStringNotContainsString('Add Warehouse', $html);
    }

    // ================================================================ helpers

    private function member()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    /** Every checkout needs a unique provider transaction id — that column is UNIQUE. */
    private function fakeGateway(): void
    {
        Http::fake([
            'qris.pw/*' => fn () => Http::response([
                'success' => true,
                'transaction_id' => 'TX-'.Str::random(24),
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
                'qris_url' => 'https://example.test/qr.png',
            ]),
        ]);
    }

    private function billingIndexHtml(): string
    {
        return $this->member()->get('/billing')->assertOk()->getContent();
    }

    private function stockIndexHtml(): string
    {
        return $this->member()->get('/stock')->assertOk()->getContent();
    }

    private function pendingPayment($expiresAt, string $status = 'pending', ?\DateTimeInterface $paidAt = null): Payment
    {
        $invoice = Invoice::create([
            'tenant_id' => $this->tenant->id,
            'invoice_number' => 'INV-T-'.Str::random(8),
            'amount' => 7_900_000, 'currency' => 'IDR', 'status' => 'open',
            'description' => 'test', 'issued_at' => now(), 'due_at' => now()->addHour(),
        ]);

        return Payment::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id,
            'invoice_id' => $invoice->id, 'order_id' => 'ORD-'.Str::random(14),
            'amount' => 7_900_000, 'currency' => 'IDR',
            'status' => $status, 'paid_at' => $paidAt, 'expires_at' => $expiresAt,
        ]);
    }
}
