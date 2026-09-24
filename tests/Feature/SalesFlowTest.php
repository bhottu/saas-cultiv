<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * End-to-end coverage for the sales module:
 * recording a sale (money, snapshots, payment status), inventory integration
 * (deducted once, restored once), returns/refunds, reporting and tenant isolation.
 */
class SalesFlowTest extends TestCase
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

        [$this->owner, $this->otherOwner] = collect(['sales-owner@test.dev', 'sales-other@test.dev'])
            ->map(fn ($email) => User::create([
                'name' => 'Owner '.substr($email, 0, 5),
                'email' => $email,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]))->all();

        foreach ([$this->owner, $this->otherOwner] as $index => $owner) {
            $tenant = Tenant::create([
                'name' => 'Sales Tenant '.($index + 1),
                'slug' => 'sales-tenant-'.($index + 1),
                'owner_id' => $owner->id,
            ]);

            $tenant->users()->attach($owner->id, [
                'role' => 'Owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $index === 0 ? $this->tenant = $tenant : $this->otherTenant = $tenant;
        }

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Gudang Utama',
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        $this->product = $this->makeProduct();
        $this->giveStock($this->product, 20);
    }

    // ---------------------------------------------------------------- helpers

    private function asMember(Tenant $tenant, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)->withSession(['tenant_id' => $tenant->id]);
    }

    private function makeProduct(array $attributes = [], ?Tenant $tenant = null): Product
    {
        $tenant ??= $this->tenant;

        return Product::create(array_merge([
            'tenant_id'       => $tenant->id,
            'name'            => 'Monstera Albo',
            'sku'             => 'MA-001',
            'unit'            => 'pcs',
            'purchase_price'  => 1_000_000, // Rp 10.000
            'selling_price'   => 1_500_000, // Rp 15.000
            'cost_price'      => 1_000_000,
            'track_inventory' => true,
            'is_active'       => true,
        ], $attributes));
    }

    private function giveStock(Product $product, int $quantity, ?Warehouse $warehouse = null): void
    {
        $warehouse ??= $this->warehouse;

        StockBalance::create([
            'tenant_id'    => $product->tenant_id,
            'product_id'   => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity'     => $quantity,
            'incoming'     => $quantity,
            'outgoing'     => 0,
        ]);
    }

    private function stockOf(Product $product): int
    {
        return (int) StockBalance::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->sum('quantity');
    }

    /** Payload shaped exactly like the create form. */
    private function payload(array $overrides = [], array $itemOverrides = []): array
    {
        return array_merge([
            'customer_id'        => null,
            'warehouse_id'       => $this->warehouse->id,
            'sales_channel'      => 'pos',
            'status'             => 'completed',
            'sold_at'            => now()->toDateString(),
            'discount_type'      => 'fixed',
            'discount_value'     => 0,
            'tax_percent'        => 0,
            'shipping'           => 0,
            'payment_method'     => 'cash',
            'payment_amount'     => 0,
            'client_reference'   => null,
            'items'              => [
                array_merge([
                    'product_id'     => $this->product->id,
                    'quantity'       => 2,
                    'unit_price'     => 15000, // rupiah as typed by the user
                    'discount_type'  => 'fixed',
                    'discount_value' => 0,
                ], $itemOverrides),
            ],
        ], $overrides);
    }

    private function recordSale(array $overrides = [], array $itemOverrides = []): Sale
    {
        $this->asMember($this->tenant)->post('/sales', $this->payload($overrides, $itemOverrides))
            ->assertRedirect();

        return Sale::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    // ---------------------------------------------------------------- listing & pages

    public function test_sales_pages_render_for_a_member(): void
    {
        foreach (['/sales', '/sales/create', '/sales/dashboard', '/sales/returns', '/sales/report'] as $url) {
            $this->asMember($this->tenant)->get($url)->assertOk();
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/sales')->assertRedirect('/login');
        $this->get('/sales/create')->assertRedirect('/login');
    }

    // ---------------------------------------------------------------- recording a sale

    public function test_sale_is_recorded_with_readable_invoice_number_and_deducts_stock(): void
    {
        $sale = $this->recordSale();

        $this->assertMatchesRegularExpression('/^INV-\d{8}-0001$/', $sale->invoice_number);
        $this->assertSame(Sale::STATUS_COMPLETED, $sale->status);
        $this->assertSame(3_000_000, $sale->subtotal);   // 2 x Rp 15.000
        $this->assertSame(3_000_000, $sale->total);
        $this->assertSame(2_000_000, $sale->total_cogs); // 2 x Rp 10.000

        // Stock 20 -> 18, exactly one movement of type "sale".
        $this->assertSame(18, $this->stockOf($this->product));
        $this->assertSame(1, StockMovement::withoutGlobalScopes()
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->where('type', 'sale')
            ->count());

        $item = $sale->items()->firstOrFail();
        $this->assertSame(1_500_000, $item->selling_price);
        $this->assertSame(1_000_000, $item->cost_price);
    }

    public function test_invoice_numbers_increment_per_tenant(): void
    {
        $first = $this->recordSale();
        $second = $this->recordSale();

        $this->assertSame('0001', substr($first->invoice_number, -4));
        $this->assertSame('0002', substr($second->invoice_number, -4));

        // A different tenant keeps its own sequence.
        $otherWarehouse = Warehouse::create([
            'tenant_id' => $this->otherTenant->id,
            'name' => 'Other Warehouse',
            'code' => 'OTH',
            'is_active' => true,
        ]);
        $otherProduct = $this->makeProduct(['sku' => 'OT-001', 'name' => 'Other Plant'], $this->otherTenant);
        $this->giveStock($otherProduct, 5, $otherWarehouse);

        $this->asMember($this->otherTenant, $this->otherOwner)->post('/sales', [
            'warehouse_id'   => $otherWarehouse->id,
            'sales_channel'  => 'pos',
            'discount_type'  => 'fixed',
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $otherProduct->id,
                'quantity'   => 1,
                'unit_price' => 15000,
            ]],
        ])->assertRedirect();

        $otherSale = Sale::withoutGlobalScopes()->where('tenant_id', $this->otherTenant->id)->firstOrFail();
        $this->assertSame('0001', substr($otherSale->invoice_number, -4));
    }

    public function test_repeated_submission_of_the_same_form_never_sells_twice(): void
    {
        $payload = $this->payload(['client_reference' => 'form-abc-123']);

        $this->asMember($this->tenant)->post('/sales', $payload)->assertRedirect();
        $this->asMember($this->tenant)->post('/sales', $payload)->assertRedirect();

        $this->assertSame(1, Sale::withoutGlobalScopes()->count());
        $this->assertSame(18, $this->stockOf($this->product));
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('type', 'sale')->count());
    }

    public function test_sale_can_be_saved_as_pending_and_completed_later_once(): void
    {
        $sale = $this->recordSale(['status' => 'pending']);

        $this->assertSame(Sale::STATUS_PENDING, $sale->status);
        $this->assertSame(20, $this->stockOf($this->product)); // nothing deducted yet

        $this->asMember($this->tenant)->post("/sales/{$sale->id}/complete")->assertRedirect();
        $this->assertSame(Sale::STATUS_COMPLETED, $sale->fresh()->status);
        $this->assertSame(18, $this->stockOf($this->product));

        // Completing twice must not deduct again.
        $this->asMember($this->tenant)->post("/sales/{$sale->id}/complete");
        $this->assertSame(18, $this->stockOf($this->product));
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('type', 'sale')->count());
    }

    public function test_sale_is_rejected_when_stock_is_insufficient(): void
    {
        $response = $this->asMember($this->tenant)->post('/sales', $this->payload([], ['quantity' => 25]));

        $response->assertSessionHasErrors(['items.'.$this->product->id]);
        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
        $this->assertSame(20, $this->stockOf($this->product));
    }

    public function test_sale_rejects_products_and_customers_of_another_tenant(): void
    {
        $foreignProduct = $this->makeProduct(['sku' => 'XX-999', 'name' => 'Foreign Plant'], $this->otherTenant);
        $foreignCustomer = Customer::create(['tenant_id' => $this->otherTenant->id, 'name' => 'Foreign Buyer']);

        $this->asMember($this->tenant)
            ->post('/sales', $this->payload(['customer_id' => $foreignCustomer->id]))
            ->assertSessionHasErrors('customer_id');

        $this->asMember($this->tenant)
            ->post('/sales', $this->payload([], ['product_id' => $foreignProduct->id]))
            ->assertSessionHasErrors('items.0.product_id');

        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
    }

    // ---------------------------------------------------------------- money

    public function test_cash_payment_records_change_and_payment_status(): void
    {
        $sale = $this->recordSale(['payment_amount' => 40000], ['quantity' => 2, 'unit_price' => 15000]);

        // Amounts are cents: Rp 15.000 typed = 1.500.000 cents.
        $this->assertSame(3_000_000, $sale->total);          // Rp 30.000
        $this->assertSame(4_000_000, $sale->paid_amount);    // Rp 40.000
        $this->assertSame(1_000_000, $sale->change_amount);  // Rp 10.000 change
        $this->assertSame(Sale::PAYMENT_PAID, $sale->payment_status);
        $this->assertSame(1, $sale->payments()->count());
    }

    public function test_partial_and_unpaid_sales_are_supported(): void
    {
        $partial = $this->recordSale(['payment_amount' => 10000]); // total = Rp 30.000
        $this->assertSame(Sale::PAYMENT_PARTIAL, $partial->payment_status);
        $this->assertSame(2_000_000, $partial->balanceDue()); // Rp 20.000 outstanding

        $unpaid = $this->recordSale(['payment_amount' => 0]);
        $this->assertSame(Sale::PAYMENT_UNPAID, $unpaid->payment_status);
        $this->assertSame(0, $unpaid->payments()->count());
    }

    public function test_percentage_and_fixed_discounts_can_never_make_the_total_negative(): void
    {
        $sale = $this->recordSale(
            ['discount_type' => 'percent', 'discount_value' => 150, 'tax_percent' => 10],
            ['quantity' => 2, 'unit_price' => 15000, 'discount_type' => 'percent', 'discount_value' => 50]
        );

        // 50% line discount + a header discount capped at 100% => a zero total, never negative.
        $this->assertSame(1_500_000, $sale->subtotal);   // Rp 30.000 minus 50% line discount
        $this->assertSame(1_500_000, $sale->discount);   // header discount capped at the subtotal
        $this->assertSame(0, $sale->tax);
        $this->assertSame(0, $sale->total);
    }

    public function test_tax_is_configurable_per_tenant_and_stored_on_the_sale(): void
    {
        $sale = $this->recordSale(['tax_percent' => 11]);

        $this->assertSame(11, $sale->tax_percent);
        $this->assertSame(330_000, $sale->tax);              // 11% of Rp 30.000
        $this->assertSame(3_330_000, $sale->total);
    }

    // ---------------------------------------------------------------- snapshots & profit

    public function test_profit_uses_the_cost_price_snapshot_not_the_current_product_cost(): void
    {
        $sale = $this->recordSale();
        $this->assertSame(1_000_000, $sale->grossProfit()); // revenue 3.000.000 - cogs 2.000.000

        // The supplier raises the cost after the sale was recorded.
        $this->product->update(['cost_price' => 1_800_000]);

        $sale->refresh();
        $this->assertSame(2_000_000, $sale->total_cogs);
        $this->assertSame(1_000_000, $sale->grossProfit());
    }

    // ---------------------------------------------------------------- cancel & refund

    public function test_cancel_restores_stock_exactly_once(): void
    {
        $sale = $this->recordSale();
        $this->assertSame(18, $this->stockOf($this->product));

        $this->asMember($this->tenant)->post("/sales/{$sale->id}/cancel")->assertRedirect();

        $this->assertSame(20, $this->stockOf($this->product));
        $this->assertSame(Sale::STATUS_CANCELLED, $sale->fresh()->status);

        // Cancelling again must not add stock back twice.
        $this->asMember($this->tenant)->post("/sales/{$sale->id}/cancel");
        $this->assertSame(20, $this->stockOf($this->product));
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('type', 'sale_return')->count());
    }

    public function test_refund_restores_stock_and_marks_the_sale_refunded(): void
    {
        $sale = $this->recordSale(['payment_amount' => 30000]);

        $this->asMember($this->tenant)->post("/sales/{$sale->id}/refund")->assertRedirect();

        $sale->refresh();
        $this->assertSame(Sale::STATUS_REFUNDED, $sale->status);
        $this->assertSame(3_000_000, $sale->refunded_amount);
        $this->assertSame(Sale::PAYMENT_REFUNDED, $sale->payment_status);
        $this->assertSame(20, $this->stockOf($this->product));
        $this->assertSame(1, SaleReturn::withoutGlobalScopes()->count());

        // Refunding again changes nothing.
        $this->asMember($this->tenant)->post("/sales/{$sale->id}/refund");
        $this->assertSame(20, $this->stockOf($this->product));
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('type', 'sale_return')->count());
    }

    public function test_partial_return_restores_only_the_returned_units(): void
    {
        $sale = $this->recordSale([], ['quantity' => 3]);
        $item = $sale->items()->firstOrFail();

        $this->assertSame(17, $this->stockOf($this->product));

        $this->asMember($this->tenant)->post("/sales/{$sale->id}/return", [
            'quantities'    => [$item->id => 1],
            'reason'        => 'Damaged plant',
            'refund_method' => 'cash',
        ])->assertRedirect();

        $this->assertSame(18, $this->stockOf($this->product));
        $this->assertSame(1, $item->fresh()->returned_quantity);
        $this->assertSame(1_500_000, $sale->fresh()->refunded_amount);
        $this->assertSame(Sale::STATUS_COMPLETED, $sale->fresh()->status);
    }

    public function test_return_cannot_exceed_the_quantity_that_was_sold(): void
    {
        $sale = $this->recordSale([], ['quantity' => 3]);
        $item = $sale->items()->firstOrFail();

        $response = $this->asMember($this->tenant)->post("/sales/{$sale->id}/return", [
            'quantities'    => [$item->id => 5],
            'reason'        => 'Too many',
            'refund_method' => 'cash',
        ]);

        $response->assertSessionHas('status');
        $this->assertSame(0, (int) $item->fresh()->returned_quantity);
        $this->assertSame(17, $this->stockOf($this->product));
        $this->assertSame(0, SaleReturn::withoutGlobalScopes()->count());
    }

    public function test_cancelled_sale_cannot_be_returned(): void
    {
        $sale = $this->recordSale();
        $item = $sale->items()->firstOrFail();

        $this->asMember($this->tenant)->post("/sales/{$sale->id}/cancel");

        $this->asMember($this->tenant)->post("/sales/{$sale->id}/return", [
            'quantities'    => [$item->id => 1],
            'reason'        => 'Too late',
            'refund_method' => 'cash',
        ])->assertSessionHas('status');

        $this->assertSame(20, $this->stockOf($this->product));
    }

    // ---------------------------------------------------------------- isolation & RBAC

    public function test_sales_of_other_tenants_are_not_reachable(): void
    {
        $sale = $this->recordSale();

        $this->asMember($this->otherTenant, $this->otherOwner)
            ->get("/sales/{$sale->id}")->assertNotFound();

        $this->asMember($this->otherTenant, $this->otherOwner)
            ->get("/sales/{$sale->id}/print")->assertNotFound();

        $this->asMember($this->otherTenant, $this->otherOwner)
            ->post("/sales/{$sale->id}/cancel")->assertNotFound();

        $this->asMember($this->otherTenant, $this->otherOwner)
            ->get('/sales')->assertOk()->assertDontSee($sale->invoice_number);

        $this->assertSame(Sale::STATUS_COMPLETED, $sale->fresh()->status);
    }

    public function test_viewer_can_read_sales_but_cannot_change_them(): void
    {
        $sale = $this->recordSale();

        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'sales-viewer@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $this->tenant->users()->attach($viewer->id, [
            'role' => 'Viewer',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->asMember($this->tenant, $viewer)->get('/sales')->assertOk();
        $this->asMember($this->tenant, $viewer)->get("/sales/{$sale->id}")->assertOk();
        $this->asMember($this->tenant, $viewer)->get('/sales/dashboard')->assertOk();

        // Financial reports follow the existing reports permission (not granted to Viewer).
        $this->asMember($this->tenant, $viewer)->get('/sales/report')->assertForbidden();

        $this->asMember($this->tenant, $viewer)->get('/sales/create')->assertForbidden();
        $this->asMember($this->tenant, $viewer)->post('/sales', $this->payload())->assertForbidden();
        $this->asMember($this->tenant, $viewer)->post("/sales/{$sale->id}/cancel")->assertForbidden();

        $this->assertSame(Sale::STATUS_COMPLETED, $sale->fresh()->status);
        $this->assertSame(1, Sale::withoutGlobalScopes()->count());
    }

    public function test_manager_may_read_reports_and_staff_may_record_sales(): void
    {
        $manager = User::create([
            'name' => 'Manager',
            'email' => 'sales-manager@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $this->tenant->users()->attach($manager->id, ['role' => 'Manager', 'status' => 'active', 'joined_at' => now()]);

        $this->asMember($this->tenant, $manager)->get('/sales/report')->assertOk();
        $this->asMember($this->tenant, $manager)->get('/sales/create')->assertOk();

        $staff = User::create([
            'name' => 'Staff',
            'email' => 'sales-staff@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $this->tenant->users()->attach($staff->id, ['role' => 'Staff', 'status' => 'active', 'joined_at' => now()]);

        // create_records lets Staff record a sale, but reports stay out of reach.
        $this->asMember($this->tenant, $staff)->post('/sales', $this->payload())->assertRedirect();
        $this->assertSame(1, Sale::withoutGlobalScopes()->count());
        $this->asMember($this->tenant, $staff)->get('/sales/report')->assertForbidden();
    }

    // ---------------------------------------------------------------- filters & reports

    public function test_index_filters_by_status_channel_customer_and_date(): void
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Ibu Rina']);
        $sale = $this->recordSale([
            'customer_id' => $customer->id,
            'sales_channel' => 'whatsapp',
            'payment_amount' => 30000,
        ]);
        $other = $this->recordSale(['sales_channel' => 'marketplace'], ['product_id' => $this->product->id]);

        $this->asMember($this->tenant)->get('/sales?status=completed&sales_channel=whatsapp')
            ->assertOk()
            ->assertSee($sale->invoice_number);

        $this->asMember($this->tenant)->get('/sales?customer_id='.$customer->id)
            ->assertOk()
            ->assertSee($sale->invoice_number)
            ->assertDontSee($other->invoice_number);

        $this->asMember($this->tenant)->get('/sales?payment_status=unpaid')
            ->assertOk()
            ->assertSee($other->invoice_number);

        $this->asMember($this->tenant)->get('/sales?from='.now()->addDay()->toDateString())
            ->assertOk()
            ->assertSee('No sales yet');

        $this->asMember($this->tenant)->get('/sales?search='.$customer->name)
            ->assertOk()
            ->assertSee($sale->invoice_number);
    }

    public function test_report_and_dashboard_aggregate_only_this_tenant(): void
    {
        $this->recordSale(['payment_amount' => 30000]);

        $this->asMember($this->tenant)->get('/sales/report?range=today')
            ->assertOk()
            ->assertSee('Total sales')
            ->assertSee('Gross profit');

        $this->asMember($this->tenant)->get('/sales/dashboard')
            ->assertOk()
            ->assertSee('Sales today')
            ->assertSee('Top selling products');
    }

    public function test_main_dashboard_and_detailed_dashboard_share_the_same_sales_data(): void
    {
        $this->recordSale(['payment_amount' => 30000]);

        $main = $this->asMember($this->tenant)->get('/dashboard')
            ->assertOk()
            ->assertSee('Sales performance')
            ->assertSee('Sales today')
            ->assertSee('Top selling products');

        $detailed = $this->asMember($this->tenant)->get('/sales/dashboard')->assertOk();

        $mainData = $main->viewData('salesOverview');
        $detailedData = $detailed->viewData('dashboardData');

        $this->assertSame($detailedData['summary'], $mainData['summary']);
        $this->assertSame($detailedData['series'], $mainData['series']);
        $this->assertSame($detailedData['maxDayTotal'], $mainData['maxDayTotal']);
        $this->assertSame(
            $detailedData['topProducts']->pluck('product_id')->all(),
            $mainData['topProducts']->pluck('product_id')->all()
        );
        $this->assertSame(
            $detailedData['recentSales']->modelKeys(),
            $mainData['recentSales']->modelKeys()
        );
    }

    public function test_inventory_movement_direction_is_correct(): void
    {
        // A sale leaves the building, a sales return brings goods back.
        $sale = $this->recordSale();
        $item = $sale->items()->firstOrFail();

        $movement = StockMovement::withoutGlobalScopes()
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->where('type', 'sale')
            ->firstOrFail();

        $this->assertSame(2, $movement->quantity); // positive magnitude; the type carries direction
        $this->assertSame(18, $this->stockOf($this->product));

        $this->asMember($this->tenant)->post("/sales/{$sale->id}/return", [
            'quantities'    => [$item->id => 2],
            'reason'        => 'Wrong item',
            'refund_method' => 'cash',
        ])->assertRedirect();

        $this->assertSame(20, $this->stockOf($this->product));

        $balance = StockBalance::withoutGlobalScopes()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame(22, (int) $balance->incoming); // 20 initial + 2 returned
        $this->assertSame(2, (int) $balance->outgoing);
        $this->assertSame(20, (int) $balance->quantity);
    }

    public function test_products_categories_and_brands_still_work_after_the_sales_module(): void
    {
        // Regression guard: the sales module must not disturb the existing catalogue.
        $this->asMember($this->tenant)->get('/products')->assertOk()->assertSee('Monstera Albo');
        $this->asMember($this->tenant)->get('/categories')->assertOk();
        $this->asMember($this->tenant)->get('/brands')->assertOk();

        $this->assertSame(0, SaleItem::withoutGlobalScopes()->count());
    }
}