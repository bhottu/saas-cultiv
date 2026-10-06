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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Billing periods: 1 / 3 / 6 / 12 months of the monthly price in one payment.
 *
 * The rules that matter here:
 *  - no `period` field means exactly what it always meant: one month at one price,
 *  - a longer period multiplies the monthly price and NOTHING else (no discount),
 *  - the period travels on the invoice metadata, so activation and renewal extend
 *    the subscription by what was actually paid,
 *  - anything outside 1 / 3 / 6 / 12 is refused before a single row is written.
 */
class BillingPeriodTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private string $whSecret = 'whsec_period_789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        config(['services.qrispw.webhook_secret' => $this->whSecret]);

        $this->owner = User::create([
            'name' => 'Period Owner', 'email' => 'period-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Period Tenant', 'slug' => 'period-tenant', 'owner_id' => $this->owner->id,
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    public function test_no_period_field_still_means_exactly_one_month(): void
    {
        $this->fakeGateway();

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);

        $payment = Payment::firstOrFail();

        $this->assertSame(39000, (int) $payment->amount);
        $this->assertSame(39000, (int) $payment->invoice->amount);
        $this->assertSame(1, (int) $payment->invoice->metadata['period_months']);
        $this->assertSame('Starter (monthly) subscription', $payment->invoice->description);
    }

    public function test_three_months_charges_triple_and_records_the_period(): void
    {
        $this->fakeGateway();

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly', 'period' => 3]);

        $payment = Payment::firstOrFail();

        // Price x months, no discount — the monthly price never changes.
        $this->assertSame(117000, (int) $payment->amount);
        $this->assertSame(117000, (int) $payment->invoice->amount);
        $this->assertSame(3, (int) $payment->invoice->metadata['period_months']);
        $this->assertSame('monthly', $payment->invoice->metadata['billing_cycle']);
        $this->assertSame('Starter (3 months) subscription', $payment->invoice->description);
    }

    public function test_twelve_months_charges_a_full_year_of_the_monthly_price(): void
    {
        $this->fakeGateway();

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly', 'period' => 12]);

        $payment = Payment::firstOrFail();

        $this->assertSame(468000, (int) $payment->amount);
        $this->assertSame(12, (int) $payment->invoice->metadata['period_months']);
    }

    public function test_any_period_outside_one_three_six_twelve_is_refused(): void
    {
        $this->fakeGateway();

        foreach ([0, 2, 5, 13] as $period) {
            $this->member()->post('/billing/checkout', [
                'plan' => 'starter', 'cycle' => 'monthly', 'period' => $period,
            ])->assertSessionHasErrors('period');
        }

        // A rejected request must never leave a half-created invoice or payment.
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_non_numeric_period_is_refused(): void
    {
        $this->fakeGateway();

        $this->member()->post('/billing/checkout', [
            'plan' => 'starter', 'cycle' => 'monthly', 'period' => 'three',
        ])->assertSessionHasErrors('period');

        $this->assertSame(0, Payment::count());
    }

    public function test_paying_a_three_month_invoice_activates_three_months(): void
    {
        $this->fakeGateway();

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly', 'period' => 3]);
        $payment = Payment::firstOrFail();

        $this->payWebhook($payment);

        $sub = Subscription::where('tenant_id', $this->tenant->id)->where('status', 'active')->firstOrFail();

        $this->assertSame(3, (int) $sub->period_months);
        $this->assertSame(117000, (int) $sub->amount);
        // Exactly three months from now (one day of slack for the clock).
        $this->assertTrue(
            $sub->current_period_end->between(now()->addMonths(3)->subDay(), now()->addMonths(3)->addDay()),
            'A 3-month prepayment must end three months out, got '.$sub->current_period_end->toDateTimeString()
        );
    }

    public function test_the_resolve_modal_carries_the_chosen_period_into_the_new_payment(): void
    {
        $this->fakeGateway();

        // First checkout without a period, then the customer picks 3 months in the
        // "cancel & create new" flow — the rebuilt payment must honour that choice.
        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);
        $first = Payment::firstOrFail();

        $this->member()->post('/billing/checkout/resolve', [
            'payment_id' => $first->id,
            'invoice_id' => $first->invoice_id,
            'plan' => 'starter',
            'cycle' => 'monthly',
            'period' => 3,
            'intent' => 'replace',
        ]);

        $second = Payment::where('status', 'pending')->firstOrFail();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(117000, (int) $second->amount);
        $this->assertSame(3, (int) $second->invoice->metadata['period_months']);
    }

    public function test_a_second_payment_renews_by_the_new_period(): void
    {
        $this->fakeGateway();

        // Pay a first 3-month invoice.
        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly', 'period' => 3]);
        $this->payWebhook(Payment::firstOrFail());

        $sub = Subscription::where('tenant_id', $this->tenant->id)->where('status', 'active')->firstOrFail();
        $firstEnd = $sub->current_period_end->copy();

        // Renew with another 3-month payment on the same plan.
        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly', 'period' => 3]);
        $this->payWebhook(Payment::where('status', 'pending')->firstOrFail());

        // Still ONE subscription (a renewal, not a second row), extended by three.
        $this->assertSame(1, Subscription::where('tenant_id', $this->tenant->id)->count());

        $sub = $sub->fresh();
        $this->assertTrue(
            $sub->current_period_end->between($firstEnd->copy()->addMonths(3)->subDay(), $firstEnd->copy()->addMonths(3)->addDay()),
            'Renewal must extend by the period just paid, got '.$sub->current_period_end->toDateTimeString()
        );
        $this->assertSame(3, (int) $sub->period_months);
        $this->assertSame(117000, (int) $sub->amount);
    }

    public function test_the_billing_page_renders_the_period_selector(): void
    {
        $html = $this->member()->get('/billing')->assertOk()->getContent();

        $this->assertStringContainsString('Payment period', $html);
        $this->assertStringContainsString('1 month', $html);
        $this->assertStringContainsString('3 months', $html);
        $this->assertStringContainsString('6 months', $html);
        $this->assertStringContainsString('12 months', $html);

        // Every plan card posts the shared selection through its own hidden field
        // (four active plans: free, starter, pro, business).
        $this->assertSame(4, substr_count($html, 'name="period" :value="period"'));
    }

    public function test_yearly_is_still_unavailable(): void
    {
        $this->fakeGateway();

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'yearly'])
            ->assertSessionHasErrors('cycle');

        $this->assertSame(0, Payment::count());
    }

    // ================================================================ helpers

    private function member()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    /** Unique transaction id per call — the column is UNIQUE across the test. */
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

    /** Deliver the signed QRIS.PW "paid" webhook for this payment. */
    private function payWebhook(Payment $payment): void
    {
        $payload = [
            'transaction_id' => $payment->provider_transaction_id,
            'order_id' => $payment->order_id,
            'amount' => (int) $payment->amount,
            'status' => 'paid',
            'paid_at' => now()->toDateTimeString(),
            'timestamp' => time(),
        ];

        $payload['signature'] = hash_hmac('sha256', json_encode($payload), $this->whSecret);

        $this->postJson('/api/webhooks/qris', $payload)->assertOk();
    }
}