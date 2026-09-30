<?php

namespace Tests\Feature;

use App\Models\BusinessInvoice;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BusinessOperationsTest extends TestCase
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

        [$this->owner, $this->otherOwner] = collect(['ops-owner@test.dev', 'ops-other@test.dev'])
            ->map(fn ($email) => User::create([
                'name' => 'Ops Owner', 'email' => $email, 'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]))->all();

        foreach ([$this->owner, $this->otherOwner] as $index => $owner) {
            $tenant = Tenant::create([
                'name' => 'Ops Tenant '.($index + 1), 'slug' => 'ops-tenant-'.($index + 1), 'owner_id' => $owner->id,
            ]);
            $tenant->users()->attach($owner->id, ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]);
            $index === 0 ? $this->tenant = $tenant : $this->otherTenant = $tenant;
        }

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Main Warehouse', 'code' => 'MAIN', 'is_active' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Arabika Gayo', 'sku' => 'ARAB-001', 'barcode' => '8990001',
            'unit' => 'pcs', 'purchase_price' => 1_000_000, 'selling_price' => 1_500_000, 'cost_price' => 1_000_000,
            'track_inventory' => true, 'is_active' => true,
        ]);
    }

    private function member(Tenant $tenant = null, User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)->withSession(['tenant_id' => ($tenant ?? $this->tenant)->id]);
    }

    private function purchasePayload(array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => Supplier::create([
                'tenant_id' => $this->tenant->id, 'name' => 'PT Kopi Nusantara', 'is_active' => true,
            ])->id,
            'warehouse_id' => $this->warehouse->id,
            'expected_at' => now()->addWeek()->toDateString(),
            'discount' => 0, 'tax' => 0, 'shipping' => 0,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 10000]],
        ], $overrides);
    }

    private function stockOf(?int $productId = null): int
    {
        return (int) StockBalance::withoutGlobalScopes()
            ->where('product_id', $productId ?? $this->product->id)
            ->value('quantity');
    }

    // -------------------------------------------------------------- warehouses

    public function test_warehouse_crud_renders_and_is_tenant_scoped(): void
    {
        $foreign = Warehouse::create(['tenant_id' => $this->otherTenant->id, 'name' => 'Gudang Asing', 'code' => 'OUT', 'is_active' => true]);

        $this->member()->get('/warehouses')->assertOk()->assertSee('Main Warehouse')->assertDontSee('Gudang Asing');
        $this->member()->get('/warehouses/create')->assertOk()->assertSee('Add Warehouse');
        $this->member()->get("/warehouses/{$this->warehouse->id}")->assertOk()->assertSee('Current stock');

        $this->member()->post('/warehouses', ['name' => 'Gudang Cadangan', 'code' => 'BACKUP', 'is_active' => 1])
            ->assertRedirect('/warehouses');
        $this->assertDatabaseHas('warehouses', ['tenant_id' => $this->tenant->id, 'code' => 'BACKUP']);

        $this->member()->put("/warehouses/{$this->warehouse->id}", ['name' => 'Gudang Utama', 'code' => 'MAIN', 'is_active' => 1])
            ->assertRedirect('/warehouses');

        // Tenant isolation: THIS workspace's owner must not read or mutate the other tenant's row.
        $this->member()->get("/warehouses/{$foreign->id}")->assertNotFound();
        $this->member()->put("/warehouses/{$foreign->id}", ['name' => 'Hijack'])->assertNotFound();
        $this->member()->delete("/warehouses/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas('warehouses', ['id' => $foreign->id, 'name' => 'Gudang Asing']);
        $this->assertDatabaseHas('warehouses', ['id' => $this->warehouse->id]);
    }

    public function test_warehouse_code_is_unique_per_tenant_only(): void
    {
        $this->member()->post('/warehouses', ['name' => 'A', 'code' => 'MAIN'])->assertSessionHasErrors('code');
        $this->member($this->otherTenant, $this->otherOwner)
            ->post('/warehouses', ['name' => 'B', 'code' => 'MAIN'])->assertRedirect('/warehouses');
    }

    public function test_warehouse_with_stock_cannot_be_deleted(): void
    {
        StockBalance::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 5, 'incoming' => 5, 'outgoing' => 0]);

        $this->member()->delete("/warehouses/{$this->warehouse->id}")->assertRedirect();
        $this->assertDatabaseHas('warehouses', ['id' => $this->warehouse->id]);
    }

    // -------------------------------------------------------------- suppliers

    public function test_supplier_crud_and_isolation(): void
    {
        $own = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'PT Kopi Nusantara', 'is_active' => true]);
        $foreign = Supplier::create(['tenant_id' => $this->otherTenant->id, 'name' => 'Supplier Asing', 'is_active' => true]);

        $this->member()->get('/suppliers')->assertOk()->assertSee('PT Kopi Nusantara')->assertDontSee('Supplier Asing');
        $this->member()->get('/suppliers/create')->assertOk();
        $this->member()->post('/suppliers', ['name' => 'CV Barokah', 'phone' => '0812', 'is_active' => 1])->assertRedirect('/suppliers');
        $supplier = Supplier::withoutGlobalScopes()->where('name', 'CV Barokah')->firstOrFail();
        $this->assertSame($this->tenant->id, $supplier->tenant_id);
        $this->member()->get("/suppliers/{$own->id}")->assertOk()->assertSee($own->name);
        $this->member()->put("/suppliers/{$supplier->id}", ['name' => 'CV Makmur', 'is_active' => 1])->assertRedirect('/suppliers');
        $this->assertSame('CV Makmur', $supplier->refresh()->name);
        $this->member()->delete("/suppliers/{$supplier->id}")->assertRedirect('/suppliers');
        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
        // Tenant isolation: the owner of THIS workspace must not reach the other tenant's row.
        $this->member()->get("/suppliers/{$foreign->id}")->assertNotFound();
        $this->member()->put("/suppliers/{$foreign->id}", ['name' => 'Hijack'])->assertNotFound();
        $this->member()->delete("/suppliers/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas('suppliers', ['id' => $foreign->id, 'name' => 'Supplier Asing']);
    }

    // -------------------------------------------------------------- purchases

    public function test_purchase_order_receive_increases_stock_and_creates_business_invoice(): void
    {
        $this->member()->post('/purchases', $this->purchasePayload())->assertRedirect();

        $purchase = Purchase::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame('ordered', $purchase->status);
        $this->assertSame('PUR-'.now()->format('Ymd').'-0001', $purchase->invoice_number);
        $this->assertSame(10_000_000, $purchase->subtotal);
        $this->assertSame(0, $this->stockOf());

        $this->assertDatabaseHas('purchase_items', ['purchase_id' => $purchase->id, 'quantity' => 10, 'unit_cost' => 1_000_000]);
        $this->assertDatabaseHas('business_invoices', ['tenant_id' => $this->tenant->id, 'purchase_id' => $purchase->id, 'total' => 10_000_000]);

        $this->member()->post("/purchases/{$purchase->id}/receive")->assertRedirect();

        $this->assertSame('received', $purchase->refresh()->status);
        $this->assertSame(10, $this->stockOf());
        $this->assertSame(1, StockMovement::withoutGlobalScopes()
            ->where('type', 'purchase')->where('reference_id', $purchase->id)->count());

        $this->member()->get('/business-invoices')->assertOk()->assertSee($purchase->invoice_number);
    }

    public function test_purchase_cannot_use_foreign_tenant_product_or_supplier(): void
    {
        $foreignProduct = Product::create(['tenant_id' => $this->otherTenant->id, 'name' => 'Foreign', 'unit' => 'pcs', 'is_active' => true]);
        $foreignSupplier = Supplier::create(['tenant_id' => $this->otherTenant->id, 'name' => 'Foreign supplier', 'is_active' => true]);

        $this->member()->post('/purchases', $this->purchasePayload(['items' => [
            ['product_id' => $foreignProduct->id, 'quantity' => 1, 'unit_cost' => 1000],
        ]]))->assertSessionHasErrors('items.0.product_id');

        $this->member()->post('/purchases', $this->purchasePayload(['supplier_id' => $foreignSupplier->id]))
            ->assertSessionHasErrors('supplier_id');

        $this->assertSame(0, Purchase::withoutGlobalScopes()->count());
    }

    public function test_received_purchase_is_immutable_and_cancel_restores_stock(): void
    {
        $this->member()->post('/purchases', $this->purchasePayload());
        $purchase = Purchase::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->member()->post("/purchases/{$purchase->id}/receive");

        $this->member()->put("/purchases/{$purchase->id}", $this->purchasePayload(['supplier_id' => $purchase->supplier_id]))
            ->assertForbidden();
        $this->member()->delete("/purchases/{$purchase->id}")->assertForbidden();

        $this->member()->post("/purchases/{$purchase->id}/cancel")->assertRedirect();
        $this->assertSame('cancelled', $purchase->refresh()->status);
        $this->assertSame(0, $this->stockOf());
    }

    // -------------------------------------------------------------- expenses

    public function test_expense_crud_money_and_category_isolation(): void
    {
        $category = ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Operasional']);
        $foreignCategory = ExpenseCategory::create(['tenant_id' => $this->otherTenant->id, 'name' => 'Asing']);

        $this->member()->get('/expenses')->assertOk();
        $this->member()->get('/expenses/create')->assertOk()->assertSee('Operasional')->assertDontSee('Asing');

        $this->member()->post('/expenses', [
            'category_id' => $category->id, 'description' => 'Listrik gudang', 'amount' => '25000.50',
            'expense_date' => now()->toDateString(), 'payment_method' => 'cash',
        ])->assertRedirect('/expenses');

        $expense = Expense::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame(2_500_050, $expense->amount);

        $this->member()->get("/expenses/{$expense->id}")->assertOk()->assertSee('Listrik gudang');
        $this->member()->put("/expenses/{$expense->id}", [
            'category_id' => $category->id, 'description' => 'Listrik & internet', 'amount' => '30000',
            'expense_date' => now()->toDateString(), 'payment_method' => 'bank',
        ])->assertRedirect('/expenses');
        $this->assertSame(3_000_000, $expense->refresh()->amount);

        $this->member()->post('/expenses', [
            'category_id' => $foreignCategory->id, 'description' => 'Nope', 'amount' => 100,
            'expense_date' => now()->toDateString(),
        ])->assertSessionHasErrors('category_id');

        $this->member()->delete("/expenses/{$expense->id}")->assertRedirect('/expenses');
        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
    }

    // ------------------------------------------------------- business invoices

    public function test_business_invoice_payment_updates_status_and_is_tenant_scoped(): void
    {
        $this->member()->post('/purchases', $this->purchasePayload());
        $purchase = Purchase::withoutGlobalScopes()->latest('id')->firstOrFail();
        $invoice = BusinessInvoice::withoutGlobalScopes()->where('purchase_id', $purchase->id)->firstOrFail();

        $this->assertSame(10_000_000, (int) $invoice->outstanding);
        $this->member($this->otherTenant, $this->otherOwner)->get("/business-invoices/{$invoice->id}")->assertNotFound();
        $this->member($this->otherTenant, $this->otherOwner)
            ->post("/business-invoices/{$invoice->id}/payments", ['method' => 'cash', 'amount' => 100])->assertNotFound();

        $this->member()->post("/business-invoices/{$invoice->id}/payments", [
            'method' => 'bank_transfer', 'amount' => '40000', 'reference' => 'TRF-1',
        ])->assertRedirect();

        $this->assertSame(4_000_000, (int) $invoice->refresh()->amount_paid);
        $this->assertSame(6_000_000, (int) $invoice->outstanding);
        $this->assertSame('partial', $invoice->status);

        $this->member()->post("/business-invoices/{$invoice->id}/payments", ['method' => 'bank_transfer', 'amount' => '60000'])
            ->assertRedirect();
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(0, (int) $invoice->outstanding);

        $this->member()->post("/business-invoices/{$invoice->id}/payments", ['method' => 'cash', 'amount' => 1])
            ->assertStatus(422);
    }

    public function test_sale_creates_a_business_invoice_for_the_finance_menu(): void
    {
        // The product needs on-hand stock, otherwise the sale is rejected for insufficient
        // stock and assertRedirect() would still pass on the back() redirect.
        StockBalance::create([
            'tenant_id' => $this->tenant->id, 'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 10, 'incoming' => 10, 'outgoing' => 0,
        ]);

        $this->member()->post('/sales', [
            'customer_id' => null, 'warehouse_id' => $this->warehouse->id, 'sales_channel' => 'pos',
            'status' => 'completed', 'discount_type' => 'fixed', 'discount_value' => 0, 'tax_percent' => 0,
            'shipping' => 0, 'payment_method' => 'cash', 'payment_amount' => 100000,
            'client_reference' => 'ops-sale-001',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 15000]],
        ])->assertRedirect();

        $invoice = BusinessInvoice::withoutGlobalScopes()->whereNotNull('sale_id')->latest('id')->firstOrFail();
        $this->member()->get('/business-invoices?type=sale')->assertOk()->assertSee($invoice->invoice_number);
    }

    // ------------------------------------------------------------------- rbac

    public function test_viewer_can_read_operations_but_not_write(): void
    {
        $viewer = User::create(['name' => 'Viewer', 'email' => 'ops-viewer@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now()]);
        $this->tenant->users()->attach($viewer->id, ['role' => 'Viewer', 'status' => 'active', 'joined_at' => now()]);

        $this->member(null, $viewer)->get('/warehouses')->assertOk();
        $this->member(null, $viewer)->get('/purchases')->assertOk();
        $this->member(null, $viewer)->get('/expenses')->assertOk();
        $this->member(null, $viewer)->get('/business-invoices')->assertOk();
        $this->member(null, $viewer)->post('/warehouses', ['name' => 'Nope'])->assertForbidden();
        $this->member(null, $viewer)->post('/expenses', [
            'description' => 'Nope', 'amount' => 10, 'expense_date' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        foreach (['/warehouses', '/suppliers', '/purchases', '/expenses', '/business-invoices'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }
}
