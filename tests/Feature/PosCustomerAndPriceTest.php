<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Module;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockBalance;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * POS: create-a-customer and editable line price.
 *
 * Both are deliberately built ON /sales/create rather than beside it — the same shared
 * modal partial, the same customers.store endpoint, the same validation rules and the
 * same server-side money path. These tests pin that reuse, because the moment the POS
 * grows its own customer form or its own total arithmetic the two screens drift apart.
 */
class PosCustomerAndPriceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;
    private Warehouse $warehouse;
    private Product $product;
    private Product $secondProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ModuleSeeder::class);

        $this->owner = User::create([
            'name' => 'POS Cashier', 'email' => 'pos-ux@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'POS UX Workspace', 'slug' => 'pos-ux-workspace',
            'owner_id' => $this->owner->id, 'status' => 'active',
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        TenantModule::create([
            'tenant_id' => $this->tenant->id,
            'module_id' => Module::where('key', 'pos')->firstOrFail()->id,
            'status' => TenantModule::STATUS_ACTIVE,
            'installed_at' => now(), 'activated_at' => now(),
        ]);

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Gudang', 'code' => 'GD', 'is_active' => true,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Umum', 'slug' => 'umum', 'is_active' => true,
        ]);
        $brand = Brand::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Merek', 'slug' => 'merek', 'is_active' => true,
        ]);

        // selling_price is stored in CENTS, like every price in this schema.
        $this->product = $this->makeProduct($category, $brand, 'PX-1', 'Produk X', 10_000_000);
        $this->secondProduct = $this->makeProduct($category, $brand, 'PX-2', 'Produk Y', 5_000_000);
    }

    // ------------------------------------------------------------------ customer

    /** The counter offers the same affordance the back-office sale screen does. */
    public function test_the_pos_offers_add_customer_using_the_shared_sales_modal(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        // Button, and the sentinel option that opens the modal from the select itself.
        $this->assertStringContainsString('id="pos-add-customer"', $html);
        $this->assertStringContainsString('value="__add_customer__"', $html);
        $this->assertStringContainsString('+ Add Customer', $html);

        // Reused verbatim, not reimplemented: the same field ids and title the sales
        // form renders.
        $this->assertStringContainsString('customer-modal-title', $html);
        $this->assertStringContainsString('id="sale_customer_name"', $html);
        $this->assertStringContainsString('id="sale_customer_phone"', $html);

        // Posted to the one shared endpoint, with the one shared Alpine component.
        $this->assertStringContainsString(route('customers.store'), $html);
        $this->assertStringContainsString('posCustomerModal', $html);
    }

    /**
     * The shared partial is Alpine-driven, so the scope that defines customerModalOpen
     * must WRAP it.
     *
     * If the modal ever renders outside its x-data wrapper, every binding in it throws
     * at boot and the dialog silently never opens — with no failing test unless one
     * checks the nesting explicitly, which is what this is for.
     */
    public function test_the_customer_modal_sits_inside_its_alpine_scope(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        $scopeAt = strpos($html, 'x-data="posCustomerModal(');
        $modalAt = strpos($html, 'id="customer-modal-title"');
        $scopeEndsAt = strpos($html, '>', $scopeAt);

        $this->assertNotFalse($scopeAt, 'The POS must define the posCustomerModal scope.');
        $this->assertNotFalse($modalAt, 'The shared customer modal must be rendered.');

        // Opened before the modal starts, and the scope has not closed by then.
        $this->assertLessThan($modalAt, $scopeAt);
        $this->assertLessThan($modalAt, $scopeEndsAt);

        // And the wrapper is properly closed after it.
        $this->assertStringContainsString('</div>', substr($html, $modalAt));
    }

    /** Creating from the counter uses the sales form's validation, phone included. */
    public function test_pos_customer_creation_uses_the_sales_form_validation(): void
    {
        $this->asOwner()->postJson('/customers', ['form_context' => 'pos', 'name' => 'Tanpa Telepon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->asOwner()->postJson('/customers', ['form_context' => 'pos', 'phone' => '0812'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(0, Customer::count());
    }

    /** The context allow-list is explicit; nothing else is accepted. */
    public function test_an_unknown_form_context_is_rejected(): void
    {
        $this->asOwner()->postJson('/customers', [
            'form_context' => 'something-else', 'name' => 'X', 'phone' => '0812',
        ])->assertStatus(422)->assertJsonValidationErrors('form_context');
    }

    /** The new customer is selectable and reaches the sale, with the cart untouched. */
    public function test_a_customer_created_at_the_counter_is_attached_to_the_sale(): void
    {
        $this->asOwner()->postJson('/customers', [
            'form_context' => 'pos', 'name' => 'Budi Santoso', 'phone' => '081234567890',
        ])->assertCreated()->assertJsonPath('customer.name', 'Budi Santoso');

        $customer = Customer::firstOrFail();
        $this->assertSame($this->tenant->id, $customer->tenant_id);

        $payload = $this->payload();
        $payload['customer_id'] = $customer->id;
        $payload['client_reference'] = 'pos-with-new-customer';

        $sale = $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertOk()->assertJsonPath('success', true)->json();

        $this->assertSame($customer->id, Sale::find($sale['sale_id'])->customer_id);
    }

    /** A customer belonging to another workspace cannot be attached to this sale. */
    public function test_a_customer_from_another_workspace_is_refused(): void
    {
        $other = Tenant::create([
            'name' => 'Other WS', 'slug' => 'other-ws', 'owner_id' => $this->owner->id, 'status' => 'active',
        ]);
        $foreign = Customer::create([
            'tenant_id' => $other->id, 'name' => 'Asing', 'phone' => '0899', 'is_active' => true,
        ]);

        $payload = $this->payload();
        $payload['customer_id'] = $foreign->id;

        $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    // ------------------------------------------------------------- editable price

    /** The price cell is a control, not decorative text. */
    public function test_the_cart_price_is_clickable_and_editable(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        $this->assertStringContainsString('data-price-edit', $html);
        $this->assertStringContainsString('data-price-input', $html);
        $this->assertStringContainsString('commitUnitPrice', $html);
        // A plain number field: the formatted "Rp 10.000" is display only.
        $this->assertStringContainsString('type="number"', $html);
    }

    /**
     * The price the cashier typed is what gets recorded — whole rupiah from the
     * browser, integer cents in the ledger.
     */
    public function test_an_edited_price_is_recorded_on_the_sale(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['unit_price'] = 15_000;   // was 100.000
        $payload['payment_amount'] = 30_000;
        $payload['client_reference'] = 'pos-edited-price-1';

        $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total_fmt', 'Rp 30.000');

        $item = SaleItem::firstOrFail();

        // 15.000 rupiah typed -> 1.500.000 cents stored. No float drift.
        $this->assertSame(1_500_000, (int) $item->selling_price);
        $this->assertSame(2, (int) $item->quantity);
        $this->assertSame(3_000_000, (int) Sale::firstOrFail()->total);
    }

    /** Editing one line must leave the others exactly as they were. */
    public function test_editing_one_line_leaves_the_others_untouched(): void
    {
        $payload = $this->payload();
        $payload['items'][] = [
            'product_id' => $this->secondProduct->id,
            'quantity' => 1,
            'unit_price' => 50_000,
            'discount_type' => 'fixed',
            'discount_value' => 0,
        ];

        $payload['items'][0]['unit_price'] = 15_000; // only the first line changes
        $payload['payment_amount'] = 80_000;    // 30.000 (2 x 15.000) + 50.000
        $payload['client_reference'] = 'pos-edited-price-2';

        $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total_fmt', 'Rp 80.000');

        $items = SaleItem::orderBy('product_id')->get()->keyBy('product_id');

        $this->assertSame(1_500_000, (int) $items[$this->product->id]->selling_price);
        $this->assertSame(5_000_000, (int) $items[$this->secondProduct->id]->selling_price);
    }

    /**
     * Hostile price input.
     *
     * The browser already refuses these in a number field, but the endpoint is
     * reachable directly, so the server is what actually has to hold.
     */
    public function test_invalid_prices_are_refused_by_the_server(): void
    {
        foreach ([
            'negative' => -5_000,
            'formatted currency' => 'Rp15.000',
            'letters' => 'fifteen thousand',
            'empty' => '',
            'not a number' => 'NaN',
        ] as $label => $value) {
            $payload = $this->payload();
            $payload['items'][0]['unit_price'] = $value;
            $payload['client_reference'] = 'pos-bad-'.md5($label);

            $this->asOwner()->postJson('/pos/checkout', $payload)
                ->assertStatus(422, "Expected a rejection for: {$label}")
                ->assertJsonValidationErrors('items.0.unit_price');

            // Nothing half-written survives a rejected price.
            $this->assertSame(0, Sale::where('client_reference', $payload['client_reference'])->count());
        }
    }

    /** A very large but legal amount is stored exactly, not as infinity. */
    public function test_a_large_price_is_stored_exactly(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['quantity'] = 1;
        $payload['items'][0]['unit_price'] = 999_999_999;
        $payload['payment_amount'] = 999_999_999;
        $payload['client_reference'] = 'pos-large-price';

        $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        // Whole rupiah in, exact integer cents out.
        $this->assertSame(99_999_999_900, (int) SaleItem::firstOrFail()->selling_price);
    }

    /** Zero is legal (a complimentary line) and must not be treated as missing. */
    public function test_a_zero_price_is_allowed(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['unit_price'] = 0;
        $payload['payment_amount'] = 0;
        $payload['client_reference'] = 'pos-zero-price';

        $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, (int) SaleItem::firstOrFail()->selling_price);
    }

    /** The client cannot sell a product that is not this workspace's. */
    public function test_a_product_from_another_workspace_is_refused(): void
    {
        $other = Tenant::create([
            'name' => 'Other WS2', 'slug' => 'other-ws-2', 'owner_id' => $this->owner->id, 'status' => 'active',
        ]);
        $foreign = $this->makeProduct(
            Category::create(['tenant_id' => $other->id, 'name' => 'C', 'slug' => 'c2', 'is_active' => true]),
            Brand::create(['tenant_id' => $other->id, 'name' => 'B', 'slug' => 'b2', 'is_active' => true]),
            'FOREIGN', 'Produk Asing', 1_000_000, $other->id,
        );

        $payload = $this->payload();
        $payload['items'][0]['product_id'] = $foreign->id;
        $payload['client_reference'] = 'pos-foreign-product';

        $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_id');
    }
// ------------------------------------------------------------------ helpers

    private function makeProduct($category, $brand, string $sku, string $name, int $priceInCents, ?int $tenantId = null)
    {
        $tenantId ??= $this->tenant->id;

        $product = Product::create([
            'tenant_id' => $tenantId, 'category_id' => $category->id, 'brand_id' => $brand->id,
            'sku' => $sku, 'name' => $name, 'unit' => 'pcs',
            'purchase_price' => $priceInCents, 'cost_price' => $priceInCents,
            'selling_price' => $priceInCents, 'minimum_stock' => 0,
            'track_inventory' => true, 'is_active' => true,
        ]);

        StockBalance::create([
            'tenant_id' => $tenantId, 'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'incoming' => 50, 'outgoing' => 0,
        ]);

        return $product;
    }

    private function asOwner()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    private function payload(): array
    {
        return [
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => null,
            'discount_type' => 'fixed',
            'discount_value' => 0,
            'tax_percent' => 0,
            'payment_method' => 'cash',
            'payment_amount' => 200_000,
            'client_reference' => 'pos-ux-ref',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => 100_000, // whole rupiah, as the browser sends it
                'discount_type' => 'fixed',
                'discount_value' => 0,
            ]],
        ];
    }
}
