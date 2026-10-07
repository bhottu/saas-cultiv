<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Manual per-period price overrides, 1/3/6/12 months. */
class ManualPlanPricesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Plan $starter;

    private User $admin;

    private Tenant $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->owner = User::create([
            'name' => 'Price Owner', 'email' => 'price-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->workspace = Tenant::create([
            'name' => 'Price Tenant', 'slug' => 'price-tenant',
            'owner_id' => $this->owner->id,
        ]);
        $this->workspace->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->admin = User::create([
            'name' => 'Platform Admin', 'email' => 'admin-price@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
            'is_platform_admin' => true,
        ]);

        $this->starter = Plan::where('slug', 'starter')->firstOrFail();
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin)->withSession(['tenant_id' => $this->workspace->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Starter',
            'description' => 'Multi-user tools for a growing business.',
            'price_monthly' => 39000,
            'price_yearly' => 0,
            'currency' => 'IDR',
            'features' => 'Multi-user',
            'entitlements' => ['max_workspaces' => 3, 'max_users' => 5],
            'is_active' => 1,
            'is_free_tier' => 0,
            'sort_order' => 2,
        ], $overrides);
    }

    public function test_period_price_overrides_monthly_times_months(): void
    {
        $plan = clone $this->starter;
        $plan->update([
            'price_1month' => 39000,
            'price_3months' => 105000,
            'price_6months' => 195000,
            'price_12months' => 360000,
        ]);

        $this->assertEquals(39000, $plan->priceForPeriod(1));
        $this->assertEquals(105000, $plan->priceForPeriod(3));
        $this->assertEquals(195000, $plan->priceForPeriod(6));
        $this->assertEquals(360000, $plan->priceForPeriod(12));
    }

    public function test_fallback_still_uses_monthly_times_months(): void
    {
        $this->assertEquals(39000, $this->starter->priceForPeriod(1));
        $this->assertEquals(117000, $this->starter->priceForPeriod(3));
        $this->assertEquals(234000, $this->starter->priceForPeriod(6));
        $this->assertEquals(468000, $this->starter->priceForPeriod(12));
    }

    public function test_has_manual_period_price_returns_false_for_zero(): void
    {
        $this->assertFalse($this->starter->hasManualPeriodPrice(1));
        $this->assertFalse($this->starter->hasManualPeriodPrice(3));
    }

    public function test_editor_can_save_and_read_back_each_period_price(): void
    {
        $this->asAdmin()->put("/admin/plans/{$this->starter->id}", $this->payload([
            'price_1month' => 39000,
            'price_3months' => 105000,
            'price_6months' => 195000,
            'price_12months' => 360000,
        ]))->assertRedirect();

        $plan = $this->starter->fresh();
        $this->assertEquals(39000, $plan->price_1month);
        $this->assertEquals(105000, $plan->price_3months);
        $this->assertEquals(195000, $plan->price_6months);
        $this->assertEquals(360000, $plan->price_12months);
    }

    public function test_zero_is_seen_as_not_manual_and_falls_back(): void
    {
        $plan = clone $this->starter;

        $this->assertEquals(39000, $plan->priceForPeriod(1));
        $this->assertEquals(117000, $plan->priceForPeriod(3));

        $plan->update(['price_1month' => 0, 'price_3months' => 0]);

        $this->assertFalse($plan->hasManualPeriodPrice(1));
        $this->assertEquals(39000, $plan->priceForPeriod(1));
        $this->assertEquals(117000, $plan->priceForPeriod(3));
    }

    public function test_checkout_charges_the_manual_period_price_not_monthly_times_months(): void
    {
        $this->fakeGateway();

        // Admin prices 3 months at Rp105.000 although 3 x 39.000 = Rp117.000.
        $this->starter->update(['price_3months' => 105000]);

        $this->member()->post('/billing/checkout', [
            'plan' => 'starter', 'cycle' => 'monthly', 'period' => 3,
        ]);

        $payment = \App\Models\Payment::firstOrFail();

        $this->assertSame(105000, (int) $payment->amount);
        $this->assertSame(105000, (int) $payment->invoice->amount);
        $this->assertSame(3, (int) $payment->invoice->metadata['period_months']);
    }

    public function test_checkout_still_falls_back_to_monthly_times_months_without_a_manual_price(): void
    {
        $this->fakeGateway();

        $this->member()->post('/billing/checkout', [
            'plan' => 'starter', 'cycle' => 'monthly', 'period' => 3,
        ]);

        $payment = \App\Models\Payment::firstOrFail();

        $this->assertSame(117000, (int) $payment->amount);
    }

    public function test_the_billing_page_renders_the_manual_period_prices(): void
    {
        $this->starter->update(['price_3months' => 105000]);

        $html = $this->member()->get('/billing?period=3')->assertOk()->getContent();

        // The Alpine price map carries formatted prices for every purchasable
        // period, and the selected price is also present in the initial HTML.
        $this->assertStringContainsString('"starter"', $html);
        $this->assertStringContainsString('Rp 105.000', $html);
        $this->assertStringContainsString("prices['starter'][period]", $html);
        $this->assertStringContainsString('x-data="JSON.parse(', $html);
        // The card must not show a debug caption.
        $this->assertStringNotContainsString('per-period price overrides', $html);
    }

    // ================================================================ helpers

    private function member()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->workspace->id]);
    }

    /** Unique transaction id per call — the column is UNIQUE across the test. */
    private function fakeGateway(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'qris.pw/*' => fn () => \Illuminate\Support\Facades\Http::response([
                'success' => true,
                'transaction_id' => 'TX-'.\Illuminate\Support\Str::random(24),
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
                'qris_url' => 'https://example.test/qr.png',
            ]),
        ]);
    }
}
