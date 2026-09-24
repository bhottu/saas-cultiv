<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SalesCreateUxTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;
    private Warehouse $warehouse;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Sales UX Owner',
            'email' => 'sales-ux-owner@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Sales UX Tenant',
            'slug' => 'sales-ux-tenant',
            'owner_id' => $this->owner->id,
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Monstera Albo',
            'sku' => 'MON-UX-001',
            'unit' => 'pcs',
            'selling_price' => 1_500_000,
            'cost_price' => 1_000_000,
            'minimum_stock' => 100,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        StockBalance::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 100,
            'incoming' => 100,
            'outgoing' => 0,
        ]);
    }

    private function member()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    private function payload(array $overrides = [], array $itemOverrides = []): array
    {
        $defaults = [
            'customer_id' => null,
            'warehouse_id' => $this->warehouse->id,
            'sales_channel' => 'pos',
            'status' => 'completed',
            'sold_at' => now()->toDateString(),
            'discount_type' => 'fixed',
            'discount_value' => 0,
            'tax_percent' => 0,
            'shipping' => 0,
            'payment_method' => 'cash',
            'payment_amount' => 30000,
            'client_reference' => 'sales-ux-submit-001',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => 15000,
                'discount_type' => 'fixed',
                'discount_value' => 0,
            ]],
        ];

        $payload = array_merge($defaults, $overrides);
        if ($itemOverrides !== []) {
            $payload['items'] = $itemOverrides;
        }

        return $payload;
    }

    public function test_create_page_uses_stock_balance_and_submit_state(): void
    {
        $this->member()->get('/sales/create')
            ->assertOk()
            ->assertSee('minimum_stock', false)
            ->assertSee('stock_recorded', false)
            ->assertSee('x-on:submit="submitting = true"', false)
            ->assertSee('x-bind:disabled="submitting"', false)
            ->assertSee('+ Add Customer', false)
            ->assertSee('__add_customer__', false)
            ->assertSee('customer-modal-title', false);
    }

    public function test_sale_submit_persists_sale_items_payment_and_deducts_stock_once(): void
    {
        $response = $this->member()->post('/sales', $this->payload());

        $response->assertRedirect();
        $sale = Sale::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(route('sales.show', $sale), $response->headers->get('Location'));
        $this->assertDatabaseHas('sales', [
            'tenant_id' => $this->tenant->id,
            'invoice_number' => $sale->invoice_number,
            'status' => Sale::STATUS_COMPLETED,
            'payment_status' => Sale::PAYMENT_PAID,
        ]);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
            'selling_price' => 1_500_000,
            'cost_price' => 1_000_000,
        ]);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $sale->id, 'amount' => 3_000_000]);
        $this->assertSame(98, (int) StockBalance::withoutGlobalScopes()->where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('type', 'sale')->where('reference_id', $sale->id)->count());
    }

    public function test_sales_validation_returns_to_form_without_creating_sale(): void
    {
        $this->member()->post('/sales', $this->payload(['items' => []]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
        $this->assertSame(100, (int) StockBalance::withoutGlobalScopes()->where('product_id', $this->product->id)->value('quantity'));
    }

    public function test_sales_customer_modal_creates_tenant_customer_as_json(): void
    {
        $this->member()->postJson('/customers', [
            'form_context' => 'sales_create',
            'name' => 'Budi',
            'phone' => '08123456789',
            'email' => 'budi@example.test',
            'address' => 'Jakarta',
        ])->assertCreated()->assertJsonPath('customer.name', 'Budi');

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Budi',
            'phone' => '08123456789',
        ]);
    }

    public function test_sales_customer_modal_requires_phone_and_rejects_duplicate(): void
    {
        $this->member()->postJson('/customers', [
            'form_context' => 'sales_create',
            'name' => 'Budi',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->member()->postJson('/customers', [
            'form_context' => 'sales_create',
            'name' => 'Budi',
            'phone' => '08123456789',
        ])->assertCreated();

        $this->member()->postJson('/customers', [
            'form_context' => 'sales_create',
            'name' => 'Budi',
            'phone' => '08123456789',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->assertSame(1, Customer::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_product_creation_creates_zero_inventory_balance(): void
    {
        $this->member()->post('/products', [
            'name' => 'New Plant',
            'sku' => 'NEW-PLANT-001',
            'unit' => 'pcs',
            'purchase_price' => 10000,
            'selling_price' => 15000,
            'cost_price' => 10000,
            'minimum_stock' => 1,
            'max_stock' => 100,
            'track_inventory' => 1,
            'is_active' => 1,
        ])->assertRedirect();

        $product = Product::withoutGlobalScopes()->where('sku', 'NEW-PLANT-001')->firstOrFail();
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 0,
        ]);
    }

    public function test_opening_adjustment_creates_balance_and_allows_sale(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Opening Plant',
            'sku' => 'OPEN-001',
            'unit' => 'pcs',
            'selling_price' => 1_500_000,
            'cost_price' => 1_000_000,
            'minimum_stock' => 1,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        $this->member()->postJson('/stock/adjust', [
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => 'in',
            'quantity' => 2,
            'reason' => 'Opening stock',
        ])->assertOk()->assertJsonPath('balance', 2);

        $response = $this->member()->post('/sales', $this->payload([
            'client_reference' => 'opening-stock-sale-001',
            'payment_amount' => 15000,
        ], [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 15000]]));
        $response->assertRedirect();

        $this->assertSame(1, (int) StockBalance::withoutGlobalScopes()->where('product_id', $product->id)->value('quantity'));
    }

    public function test_sale_rejects_product_without_inventory_record(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Unstocked Plant',
            'sku' => 'UNSTOCKED-001',
            'unit' => 'pcs',
            'selling_price' => 1_500_000,
            'cost_price' => 1_000_000,
            'minimum_stock' => 1,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        $response = $this->member()->post('/sales', $this->payload([
            'client_reference' => 'unstocked-sale-001',
        ], [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 15000]]));
        $response->assertSessionHasErrors('items.'.$product->id);

        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
    }
    public function test_stock_page_renders_stock_and_adjustment_controls(): void
    {
        $this->member()->get('/stock')
            ->assertOk()
            ->assertSee('Monstera Albo')
            ->assertSee('Stock adjustment / opening balance')
            ->assertSee('Current stock');
    }



}