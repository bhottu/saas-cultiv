<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Module;
use App\Models\Product;
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
 * The rebuilt POS terminal.
 *
 * The browser does no money math — it renders what the server says — so these
 * tests pin the exact contract pos/index.blade.php is built on: the grid JSON
 * shape (cents + whole-rupiah label), the scanner hooks in the HTML, and the
 * checkout payload the terminal posts (idempotent, stock-deducting).
 */
class PosTerminalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;
    private Warehouse $warehouse;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ModuleSeeder::class);

        $this->owner = User::create([
            'name' => 'Cashier Owner', 'email' => 'pos-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Pos Workspace', 'slug' => 'pos-workspace',
            'owner_id' => $this->owner->id, 'status' => 'active',
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        TenantModule::create([
            'tenant_id'    => $this->tenant->id,
            'module_id'    => Module::where('key', 'pos')->firstOrFail()->id,
            'status'       => TenantModule::STATUS_ACTIVE,
            'installed_at' => now(),
            'activated_at' => now(),
        ]);

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Kas Toko', 'code' => 'KAS', 'is_active' => true,
        ]);

        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Plants', 'slug' => 'plants', 'is_active' => true]);
        $brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'Nusantara', 'slug' => 'nusantara', 'is_active' => true]);

        // Rp 100.000 stored as 10.000.000 cents: the exact value a 100x display
        // bug would render as "Rp 10.000.000".
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'brand_id' => $brand->id,
            'sku' => 'KPI-100', 'barcode' => '8993000111222', 'name' => 'Kopi Susu 100ml',
            'unit' => 'pcs', 'purchase_price' => 6_000_000, 'cost_price' => 6_000_000,
            'selling_price' => 10_000_000, 'minimum_stock' => 0,
            'track_inventory' => true, 'is_active' => true,
        ]);

        StockBalance::create([
            'tenant_id' => $this->tenant->id, 'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 20, 'incoming' => 20, 'outgoing' => 0,
        ]);
    }

    private function asOwner()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }


    public function test_terminal_renders_scanner_bridge_and_checkout_surface(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        // The shared scanner component and the exact hooks the cart engine binds to.
        $this->assertStringContainsString('barcodeScanner()', $html);
        $this->assertStringContainsString('barcode-scanned', $html);
        $this->assertStringContainsString('pos-barcode', $html);
        $this->assertStringContainsString('addScannedToCart', $html);
        $this->assertStringContainsString('id="pos-checkout"', $html);
        $this->assertStringContainsString('id="pos-grand-total"', $html);
    }

    public function test_grid_labels_prices_in_whole_rupiah_not_a_hundredfold(): void
    {
        $json = $this->asOwner()->getJson('/pos/products?warehouse_id='.$this->warehouse->id)
            ->assertOk()
            ->assertJsonPath('products.0.barcode', '8993000111222')
            ->assertJsonPath('products.0.price', 10_000_000)
            ->json();

        $this->assertSame('Rp 100.000', $json['products'][0]['price_formatted']);
        $this->assertStringNotContainsString('10.000.000', $json['products'][0]['price_formatted']);
    }

    public function test_checkout_records_a_completed_sale_and_deducts_stock_once(): void
    {
        $payload = $this->checkoutPayload('pos-test-ref-1');

        $first = $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json();

        // Replaying the same tap resolves to the SAME sale, never a second one.
        $replay = $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json();

        $this->assertSame($first['invoice_number'], $replay['invoice_number']);
        $this->assertSame('Rp 200.000', $first['total_fmt']);
        $this->assertSame(18, (int) StockBalance::withoutGlobalScopes()
            ->where('product_id', $this->product->id)->sum('quantity'));
    }

    /**
     * The server does not reject an underpayment: RecordSaleService records what was
     * handed over and reports zero change, so the terminal's own guard (paid < total
     * blocks checkout) is the floor. Pinned here so the client-side guard is not the
     * only thing that knows it.
     */
    public function test_checkout_records_an_underpayment_as_a_partial_payment(): void
    {
        $payload = $this->checkoutPayload('pos-test-ref-2');
        $payload['payment_amount'] = 50_000;

        $sale = $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json();

        $this->assertSame('Rp 0', $sale['change_fmt']);
    }

    /**
     * Whatever casing the cashier types or the scanner sends, the grid must find
     * the product. The server folds both sides of the match (Product::scopeSearch)
     * because PostgreSQL's LIKE is case-sensitive — pin kopi/KOPI/Kopi here.
     */
    public function test_grid_search_is_case_insensitive_by_name_sku_and_barcode(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Teh Manis Jumbo', 'sku' => 'TEH-200', 'unit' => 'pcs',
            'purchase_price' => 1_000_000, 'selling_price' => 2_000_000, 'cost_price' => 1_000_000,
            'is_active' => true,
        ]);

        $search = fn (string $term) => $this->asOwner()
            ->getJson('/pos/products?warehouse_id='.$this->warehouse->id.'&q='.urlencode($term))
            ->assertOk()
            ->json('products');

        foreach (['kopi', 'KoPI', 'KOPI'] as $term) {
            $names = collect($search($term))->pluck('name');

            $this->assertTrue($names->contains('Kopi Susu 100ml'), "[{$term}] must match by name.");
            $this->assertFalse($names->contains('Teh Manis Jumbo'), "[{$term}] must not match the other product.");
        }

        // SKU in the wrong casing still resolves, and barcode digit search keeps working.
        $this->assertTrue(collect($search('kpi-100'))->pluck('name')->contains('Kopi Susu 100ml'));
        $this->assertTrue(collect($search('8993000111222'))->pluck('name')->contains('Kopi Susu 100ml'));
    }

    /** The exact JSON shape pos/index.blade.php posts (whole-rupiah unit_price). */
    private function checkoutPayload(string $reference): array
    {
        return [
            'warehouse_id'     => $this->warehouse->id,
            'customer_id'      => null,
            'discount_type'    => 'fixed',
            'discount_value'   => 0,
            'tax_percent'      => 0,
            'payment_method'   => 'cash',
            'payment_amount'   => 200_000,
            'client_reference' => $reference,
            'items'            => [[
                'product_id'     => $this->product->id,
                'quantity'       => 2,
                'unit_price'     => 100_000,
                'discount_type'  => 'fixed',
                'discount_value' => 0,
            ]],
        ];
    }
}
