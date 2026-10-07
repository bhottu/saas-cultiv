<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentResultTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Billing Owner',
            'email' => 'payment-result@test.dev',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $this->tenant = Tenant::create([
            'name' => 'Result Tenant',
            'slug' => 'result-tenant',
            'owner_id' => $this->owner->id,
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->plan = Plan::factory()->create([
            'name' => 'Business',
            'slug' => 'business-result-test',
            'price_monthly' => 79_000,
        ]);
    }

    public function test_pending_kasera_result_shows_plan_and_checkout_without_provider_identifier_or_expiry(): void
    {
        $payment = $this->payment('pending', [
            'checkout_url' => 'https://pay.kasera.id/checkout/session-1',
            'qris_url' => 'https://kasera.test/provider-qr.png',
            'qris_string' => 'KASERA.TEST.MODE/provider-identifier',
        ]);

        $html = $this->member()
            ->get(route('billing.pay', $payment).'?status=succeeded')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Business', $html);
        $this->assertStringContainsString('Rp 79.000', $html);
        $this->assertStringContainsString('You will be redirected to the payment page', $html);
        $this->assertStringNotContainsString('Kasera Pay', strip_tags($html));
        $this->assertStringContainsString('https://pay.kasera.id/checkout/session-1', $html);
        $this->assertStringContainsString('Pay now', $html);
        $this->assertStringContainsString('data-initial-status="pending"', $html);
        $this->assertStringContainsString('ORD-RESULT-1', $html);
        $this->assertStringContainsString('href="https://pay.kasera.id/checkout/session-1"', $html);
        $this->assertStringNotContainsString('https://kasera.test/provider-qr.png', $html);
        $this->assertStringNotContainsString('KASERA.TEST.MODE/provider-identifier', $html);
        $this->assertStringNotContainsString('Scan QRIS to pay', $html);
        $this->assertStringNotContainsString('Expires', $html);
        $this->assertStringContainsString("'pending'", $html);
    }

    public function test_payment_result_uses_generic_indonesian_payment_copy(): void
    {
        $payment = $this->payment('pending', []);
        $html = $this->member()
            ->get(route('billing.pay', $payment))
            ->assertOk()
            ->getContent();

        $visibleText = strip_tags($html);
        $this->assertStringNotContainsString('Kasera Pay', $visibleText);
        $this->assertSame(
            'Anda akan dialihkan ke halaman pembayaran.',
            __('You will be redirected to the payment page', [], 'id')
        );
        $this->assertSame(
            'Halaman pembayaran tidak tersedia. Silakan kembali ke billing dan coba lagi.',
            __('Payment checkout is unavailable. Please return to billing and try again.', [], 'id')
        );
    }

    public function test_paid_result_uses_confirmed_payment_and_links_to_billing_without_checkout_artifacts(): void
    {
        $payment = $this->payment('paid', [
            'checkout_url' => 'https://pay.kasera.id/checkout/session-2',
            'qris_string' => 'KASERA.TEST.MODE/provider-identifier',
        ]);

        $html = $this->member()
            ->get(route('billing.pay', $payment).'?status=pending')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Payment successful', $html);
        $this->assertStringContainsString('Your payment was successful.', $html);
        $this->assertStringContainsString('Business', $html);
        $this->assertStringContainsString('3 months', $html);
        $this->assertStringContainsString('Rp 79.000', $html);
        $this->assertStringContainsString('Successful', $html);
        $this->assertStringContainsString('ORD-RESULT-1', $html);
        $this->assertStringContainsString('href="'.route('billing.index').'"', $html);
        $this->assertStringContainsString('data-initial-status="paid"', $html);
        $this->assertStringNotContainsString('https://pay.kasera.id/checkout/session-2', $html);
        $this->assertStringNotContainsString('KASERA.TEST.MODE/provider-identifier', $html);
        $this->assertStringNotContainsString('Expires', $html);
        $this->assertStringNotContainsString('Scan QRIS to pay', $html);
    }

    public function test_paid_qrispw_result_uses_the_shared_success_state_without_rendering_old_qr(): void
    {
        $payment = $this->payment('paid', [
            'qris_url' => 'https://qr.qris.pw/img/settled.png',
        ], 'qrispw');

        $this->member()
            ->get(route('billing.pay', $payment))
            ->assertOk()
            ->assertSee('Payment successful')
            ->assertSee('ORD-RESULT-1')
            ->assertDontSee('/img/settled.png');
    }

    public function test_pending_qrispw_result_still_shows_its_payment_code(): void
    {
        $payment = $this->payment('pending', [
            'qris_url' => 'https://qr.qris.pw/img/pending.png',
        ], 'qrispw');

        $this->member()
            ->get(route('billing.pay', $payment))
            ->assertOk()
            ->assertSee('Business')
            ->assertSee('https://qr.qris.pw/img/pending.png')
            ->assertSee('Please scan the QR code to complete your payment')
            ->assertDontSee('Pay now')
            ->assertDontSee('Expires');
    }

    public function test_failed_and_expired_results_are_not_shown_as_success(): void
    {
        foreach (['failed', 'expired'] as $status) {
            $payment = $this->payment($status, [
                'checkout_url' => 'https://pay.kasera.id/checkout/'.$status,
                'qris_string' => 'KASERA.TEST.MODE/'.$status,
            ]);

            $this->member()
                ->get(route('billing.pay', $payment).'?status=succeeded')
                ->assertOk()
                ->assertSee('Payment could not be completed.')
                ->assertDontSee('Payment successful')
                ->assertDontSee('https://pay.kasera.id/checkout/'.$status)
                ->assertDontSee('KASERA.TEST.MODE/'.$status);
        }
    }

    private function payment(string $status, array $createResponse, string $provider = 'kasera'): Payment
    {
        $sequence = Invoice::count() + 1;
        $invoice = Invoice::create([
            'tenant_id' => $this->tenant->id,
            'invoice_number' => 'INV-RESULT-'.$sequence,
            'amount' => 79_000,
            'currency' => 'IDR',
            'status' => $status === 'paid' ? 'paid' : 'open',
            'description' => 'Business subscription',
            'metadata' => [
                'plan_id' => $this->plan->id,
                'billing_cycle' => 'monthly',
                'period_months' => 3,
            ],
        ]);

        return Payment::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'invoice_id' => $invoice->id,
            'provider' => $provider,
            'provider_transaction_id' => 'payreq-result-'.$sequence,
            'order_id' => 'ORD-RESULT-'.$sequence,
            'amount' => 79_000,
            'currency' => 'IDR',
            'status' => $status,
            'paid_at' => $status === 'paid' ? now() : null,
            'expires_at' => now()->addMinutes(10),
            'payload' => ['create' => $createResponse],
        ]);
    }

    private function member()
    {
        return $this->actingAs($this->owner)
            ->withSession(['tenant_id' => $this->tenant->id]);
    }
}
