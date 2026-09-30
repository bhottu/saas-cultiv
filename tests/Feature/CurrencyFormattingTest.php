<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Money;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Currency formatting.
 *
 * Cultiv One stores money in two integer units and both conventions are pinned by
 * existing tests (SalesFlowTest for cents, QrisWebhookTest for whole rupiah). The
 * display layer is therefore responsible for showing the REAL amount in both cases,
 * which is what these assertions pin: the exact string a user reads.
 */
class CurrencyFormattingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private Tenant $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->admin = User::create([
            'name' => 'Currency Admin',
            'email' => 'currency-admin@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_platform_admin' => true,
        ]);

        $this->owner = User::create([
            'name' => 'Currency Owner',
            'email' => 'currency-owner@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $this->workspace = Tenant::create([
            'name' => 'Currency Workspace',
            'slug' => 'currency-workspace',
            'owner_id' => $this->owner->id,
        ]);
        $this->workspace->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin)->withSession(['tenant_id' => $this->workspace->id]);
    }

    // ------------------------------------------------------------------ formatter

    public function test_the_formatter_renders_the_value_the_database_actually_stores(): void
    {
        // Business tables store minor units: 665.000.000 cents is Rp 6.650.000.
        $this->assertSame('Rp 6.650.000', Money::format(665_000_000));
        $this->assertSame('Rp 0', Money::format(0));
        $this->assertSame('Rp 15.000', Money::format(1_500_000));

        // SaaS billing tables store whole rupiah: 39000 IS Rp 39.000.
        $this->assertSame('Rp 39.000', Money::formatRupiah(39_000));
        $this->assertSame('Rp 79.000', Money::formatRupiah(79_000));
        $this->assertSame('Rp 149.000', Money::formatRupiah(149_000));
        $this->assertSame('Rp 6.650.000', Money::formatRupiah(6_650_000));
        $this->assertSame('Rp 0', Money::formatRupiah(0));

        // Whole rupiah never gains a ",00" tail.
        $this->assertStringNotContainsString(',', Money::formatRupiah(39_000));
        $this->assertStringNotContainsString(',', Money::format(665_000_000));
    }

    // ------------------------------------------------------------------ admin dashboard

    public function test_admin_dashboard_sales_value_is_shown_as_rupiah(): void
    {
        Sale::withoutGlobalScopes()->create([
            'tenant_id' => $this->workspace->id,
            'invoice_number' => 'INV-CURRENCY-1',
            'status' => Sale::STATUS_COMPLETED,
            'payment_status' => Sale::PAYMENT_PAID,
            'subtotal' => 665_000_000,
            'discount' => 0, 'tax' => 0, 'shipping' => 0,
            'total' => 665_000_000,
            'total_cogs' => 0,
            'sold_at' => now(),
        ]);

        $this->asAdmin()->get('/admin')->assertOk()
            ->assertSee('Rp 6.650.000', false)
            ->assertDontSee('665,000,000', false);
    }

    // ------------------------------------------------------------------ admin payments

    public function test_admin_payments_show_the_stored_rupiah_amount(): void
    {
        $invoice = Invoice::create([
            'tenant_id' => $this->workspace->id,
            'invoice_number' => 'INV-CURRENCY-PAY',
            'amount' => 39_000,
            'currency' => 'IDR',
            'status' => 'pending',
            'description' => 'Starter (monthly) subscription',
        ]);

        Payment::create([
            'tenant_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'invoice_id' => $invoice->id,
            'provider' => 'qrispw',
            'order_id' => 'ORD-CURRENCY-1',
            'amount' => 39_000,
            'currency' => 'IDR',
            'status' => 'pending',
        ]);

        $this->asAdmin()->get('/admin/payments')->assertOk()
            ->assertSee('Rp 39.000', false)
            ->assertDontSee('Rp 390,00', false);
    }

    // ------------------------------------------------------------------ admin plans

    public function test_admin_plans_show_the_seeded_rupiah_prices(): void
    {
        $this->asAdmin()->get('/admin/plans')->assertOk()
            ->assertSee('Rp 0', false)
            ->assertSee('Rp 39.000', false)
            ->assertSee('Rp 79.000', false)
            ->assertSee('Rp 149.000', false)
            // The old minor-unit rendering of the same numbers.
            ->assertDontSee('Rp 390,00', false)
            ->assertDontSee('Rp 790,00', false)
            ->assertDontSee('Rp 1.490,00', false);
    }
}
