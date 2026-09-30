<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Advanced reports + analytics.
 *
 * Covers entitlement (Free/Starter blocked), correctness (numbers come from the
 * database) and isolation (another workspace's data is never included).
 */
class AdvancedReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $otherOwner;
    private Tenant $tenant;
    private Tenant $otherTenant;
    private Warehouse $warehouse;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        [$this->owner, $this->otherOwner] = collect(['rep-owner@test.dev', 'rep-other@test.dev'])
            ->map(fn ($email) => User::create([
                'name' => 'Report Owner', 'email' => $email, 'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]))->all();

        foreach ([$this->owner, $this->otherOwner] as $index => $owner) {
            $tenant = Tenant::create([
                'name' => 'Report Tenant '.($index + 1), 'slug' => 'report-tenant-'.($index + 1),
                'owner_id' => $owner->id,
            ]);
            $tenant->users()->attach($owner->id, ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]);
            $index === 0 ? $this->tenant = $tenant : $this->otherTenant = $tenant;
        }

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Main Warehouse', 'code' => 'MAIN', 'is_active' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Arabika Gayo', 'sku' => 'REP-001',
            'unit' => 'pcs', 'purchase_price' => 1_000_000, 'selling_price' => 1_500_000,
            'cost_price' => 1_000_000, 'minimum_stock' => 2, 'track_inventory' => true, 'is_active' => true,
        ]);

        StockBalance::create([
            'tenant_id' => $this->tenant->id, 'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 10, 'incoming' => 10, 'outgoing' => 0,
        ]);
    }

    /** Attach an ACTIVE subscription of $planSlug, then sign in as that workspace. */
    private function member(Tenant $tenant = null, User $user = null, string $planSlug = 'pro')
    {
        $tenant = $tenant ?? $this->tenant;
        $plan = Plan::where('slug', $planSlug)->firstOrFail();

        Subscription::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'billing_cycle' => 'monthly', 'amount' => $plan->price_monthly, 'currency' => $plan->currency,
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);

        return $this->actingAs($user ?? $this->owner)->withSession(['tenant_id' => $tenant->id]);
    }

    /** A completed sale worth $total whose COGS is $cogs. */
    private function makeSale(Tenant $tenant, int $total, int $cogs, ?string $channel = 'pos'): Sale
    {
        $sale = Sale::create([
            'tenant_id' => $tenant->id, 'customer_id' => null, 'warehouse_id' => $this->warehouse->id,
            'invoice_number' => 'INV-'.uniqid(), 'sold_at' => now(), 'subtotal' => $total,
            'discount' => 0, 'tax' => 0, 'shipping' => 0, 'total' => $total, 'total_cogs' => $cogs,
            'paid_amount' => $total, 'status' => Sale::STATUS_COMPLETED,
            'payment_status' => Sale::PAYMENT_PAID, 'sales_channel' => $channel,
            'payment_method' => 'cash', 'created_by' => $tenant->owner_id,
        ]);

        SaleItem::create([
            'tenant_id' => $tenant->id, 'sale_id' => $sale->id, 'product_id' => $this->product->id,
            'product_name' => $this->product->name, 'sku' => $this->product->sku, 'unit' => 'pcs',
            'quantity' => 1, 'selling_price' => $total, 'cost_price' => $cogs, 'subtotal' => $total,
        ]);

        return $sale;
    }

    // ---------------------------------------------------------------- entitlement

    public function test_free_plan_cannot_open_advanced_reports_or_analytics(): void
    {
        $this->member(planSlug: 'free')->get('/reports')->assertForbidden();
        $this->member(planSlug: 'free')->get('/reports/sales')->assertForbidden();
        $this->member(planSlug: 'free')->get('/analytics')->assertForbidden();
    }

    public function test_starter_is_blocked_but_business_is_allowed(): void
    {
        $this->member(planSlug: 'starter')->get('/reports')->assertForbidden();
        $this->member(planSlug: 'business')->get('/reports')->assertOk();
        $this->member(planSlug: 'business')->get('/analytics')->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/reports')->assertRedirect('/login');
        $this->get('/analytics')->assertRedirect('/login');
    }

    // ---------------------------------------------------------------- rendering

    public function test_every_report_page_renders_for_a_business_plan(): void
    {
        $this->makeSale($this->tenant, 1_500_000, 1_000_000);
        $this->makeSale($this->tenant, 500_000, 300_000, 'online');

        foreach (['/reports', '/reports/sales', '/reports/inventory', '/reports/purchases',
                  '/reports/customers', '/reports/profit', '/analytics'] as $url) {
            $this->member(planSlug: 'business')->get($url)->assertOk();
        }
    }

    public function test_reports_page_links_to_every_report(): void
    {
        $this->member(planSlug: 'business')->get('/reports')
            ->assertOk()
            ->assertSee(route('reports.sales'), false)
            ->assertSee(route('reports.inventory'), false)
            ->assertSee(route('reports.purchases'), false)
            ->assertSee(route('reports.customers'), false)
            ->assertSee(route('reports.profit'), false);
    }

    // ---------------------------------------------------------------- numbers

    public function test_sales_report_totals_match_the_database(): void
    {
        $this->makeSale($this->tenant, 1_500_000, 1_000_000);
        $this->makeSale($this->tenant, 500_000, 300_000);

        $this->member()->get('/reports/sales?period=month')
            ->assertOk()
            // 2,000,000 gross sales, 1,300,000 COGS, 700,000 gross profit, 1,000,000 AOV.
            ->assertSee(Money::format(2_000_000), false)
            ->assertSee(Money::format(700_000), false)
            ->assertSee(Money::format(1_000_000), false);
    }

    public function test_profit_report_subtracts_expenses_from_gross_profit(): void
    {
        $this->makeSale($this->tenant, 1_500_000, 1_000_000);

        Expense::create([
            'tenant_id' => $this->tenant->id, 'description' => 'Listrik', 'amount' => 250_000,
            'expense_date' => now()->toDateString(), 'payment_method' => 'cash', 'created_by' => $this->owner->id,
        ]);

        // Gross profit 500,000 − expense 250,000 = net profit 250,000.
        $this->member()->get('/reports/profit?period=month')
            ->assertOk()
            ->assertSee(Money::format(250_000), false);
    }

    public function test_inventory_report_reflects_stock_and_movements(): void
    {
        StockMovement::create([
            'tenant_id' => $this->tenant->id, 'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id, 'type' => 'adjustment_in', 'quantity' => 4,
            'reference_type' => 'adjustment', 'created_by' => $this->owner->id, 'created_at' => now(),
        ]);

        $this->member()->get('/reports/inventory?period=month')
            ->assertOk()
            ->assertSee(__('Stock in (units)'))
            // 10 opening + 4 adjustment = 14 units on hand.
            ->assertSee(number_format(14), false);
    }

    public function test_custom_period_excludes_sales_outside_the_window(): void
    {
        $this->makeSale($this->tenant, 1_500_000, 1_000_000);

        // The window holds no sale, so the Net sales KPI must be zero. The product's
        // catalogue price is unrelated and still renders elsewhere on the page, so the
        // assertion targets the KPI value rather than a bare formatted amount.
        $this->member()->get('/reports/sales?period=custom&from=2020-01-01&to=2020-01-31')
            ->assertOk()
            ->assertSee('<div class="mt-1 text-lg font-bold text-gray-900">'.Money::format(0).'</div>', false);
    }

    // ---------------------------------------------------------------- isolation

    public function test_reports_never_include_another_workspaces_sales(): void
    {
        $this->makeSale($this->tenant, 1_500_000, 1_000_000);
        $this->makeSale($this->otherTenant, 9_000_000, 8_000_000);

        $this->member()->get('/reports/sales?period=month')
            ->assertOk()
            ->assertSee(Money::format(1_500_000), false)
            ->assertDontSee(Money::format(9_000_000), false);
    }

    public function test_analytics_does_not_leak_another_workspaces_sales(): void
    {
        $this->makeSale($this->tenant, 1_500_000, 1_000_000);
        $this->makeSale($this->otherTenant, 9_000_000, 8_000_000);

        // Tenant B sees only its own 90,000.00 net-sales KPI; the 15,000.00 sale of
        // tenant A never reaches the page.
        $this->member($this->otherTenant, $this->otherOwner, 'business')
            ->get('/analytics?period=month')
            ->assertOk()
            ->assertSee(Money::format(9_000_000), false)
            ->assertDontSee(Money::format(1_500_000), false);
    }
}
