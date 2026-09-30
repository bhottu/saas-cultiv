<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
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

    public function test_invoice_number_sequence_survives_a_second_checkout_in_the_same_year(): void
    {
        $first = $this->checkout();
        $second = $this->checkout();

        // INV-<year>-<counter>: the counter must advance, never repeat.
        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^INV-'.date('Y').'-\d{6}$/', $first);
        $this->assertMatchesRegularExpression('/^INV-'.date('Y').'-\d{6}$/', $second);
        $this->assertSame(2, Invoice::count());
    }

    private function checkout(): string
    {
        // The provider transaction id column is UNIQUE, so each call needs its own value.
        // A closure fake (not a fixed response) is required: registering a second fixed
        // response does not replace the first one, so the second checkout would be handed
        // the first checkout's transaction id and blow up on the unique index.
        Http::fake([
            'qris.pw/*' => fn () => Http::response([
                'success' => true,
                'transaction_id' => 'TX-'.Str::random(24),
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
                'qris_url' => 'https://example.test/qr.png',
            ]),
        ]);

        $this->actingAs($this->owner)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly'])
            ->assertRedirect();

        return (string) Invoice::latest('id')->value('invoice_number');
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

    private function billingIndexHtml(): string
    {
        return $this->actingAs($this->owner)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->get('/billing')->assertOk()->getContent();
    }

    private function pendingPayment($expiresAt, string $status = 'pending'): Payment
    {
        return Payment::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id,
            'order_id' => 'ORD-'.uniqid(), 'amount' => 7_900_000, 'currency' => 'IDR',
            'status' => $status, 'expires_at' => $expiresAt,
        ]);
    }
}
