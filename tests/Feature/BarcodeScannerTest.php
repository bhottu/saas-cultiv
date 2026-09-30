<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Barcode scanner integration.
 *
 * The decoder itself is client-side (jsQR + getUserMedia), so what is verified
 * here is the contract it depends on: the server must expose `barcode` to the
 * sales form, and the scanner UI must be present on the pages that use it.
 */
class BarcodeScannerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Scan Owner', 'email' => 'scan-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Scan Workspace', 'slug' => 'scan-workspace', 'owner_id' => $this->owner->id,
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Main Warehouse', 'code' => 'MAIN', 'is_active' => true,
        ]);
    }

    private function product(array $attrs = []): Product
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Plants', 'slug' => 'plants', 'is_active' => true]);
        $brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'Nusantara', 'slug' => 'nusantara', 'is_active' => true]);

        return Product::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'sku' => 'MON-ALBO',
            'barcode' => '8991234567890',
            'name' => 'Monstera Albo',
            'unit' => 'pcs',
            'purchase_price' => 1_000_000,
            'cost_price' => 1_000_000,
            'selling_price' => 1_500_000,
            'minimum_stock' => 5,
            'track_inventory' => true,
            'is_active' => true,
        ], $attrs));
    }

    private function asOwner()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    public function test_sales_create_exposes_the_barcode_to_the_scanner(): void
    {
        $product = $this->product();

        // The Alpine payload is rendered into the page as JSON, so the decoded
        // barcode can be matched without a second round-trip.
        $html = $this->asOwner()->get('/sales/create')->assertOk()->getContent();

        $this->assertStringContainsString('8991234567890', $html);
        $this->assertStringContainsString('addByCode', $html);
        $this->assertStringContainsString('barcode-scanned', $html);
        $this->assertStringContainsString((string) $product->id, $html);
    }

    public function test_sales_create_renders_the_scanner_trigger(): void
    {
        $this->product();

        $html = $this->asOwner()->get('/sales/create')->assertOk()->getContent();

        $this->assertStringContainsString('barcodeScanner()', $html);
    }

    public function test_scanner_source_uses_the_rear_camera_and_releases_the_stream(): void
    {
        $source = file_get_contents(base_path('resources/js/barcode-scanner.js'));

        // The decoder lives in the bundle, not the HTML, so it is verified here.
        $this->assertStringContainsString('getUserMedia', $source);
        $this->assertStringContainsString("facingMode", $source);
        $this->assertStringContainsString("environment", $source);
        $this->assertStringContainsString("jsQR", $source);

        // A leaked camera track keeps the recording indicator lit, so the stream
        // must be stopped on close as well as on unmount.
        $this->assertStringContainsString('track.stop()', $source);
        $this->assertStringContainsString('destroy()', $source);

        // Camera problems are common; the component must not dead-end.
        $this->assertStringContainsString('isSecureContext', $source);
    }

    public function test_products_index_offers_scan_to_find(): void
    {
        $this->product();

        $html = $this->asOwner()->get('/products')->assertOk()->getContent();

        $this->assertStringContainsString('barcodeScanner()', $html);
        $this->assertStringContainsString('findByCode', $html);
    }

    public function test_scanner_only_ever_matches_products_of_this_workspace(): void
    {
        $mine = $this->product(['barcode' => '1111111111111', 'name' => 'Mine']);

        $stranger = User::create([
            'name' => 'Other', 'email' => 'scan-other@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $other = Tenant::create(['name' => 'Other WS', 'slug' => 'other-ws', 'owner_id' => $stranger->id]);
        $other->users()->attach($stranger->id, ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]);

        Product::create([
            'tenant_id' => $other->id, 'sku' => 'FOREIGN', 'barcode' => '9999999999999',
            'name' => 'Foreign Plant', 'unit' => 'pcs', 'selling_price' => 100,
            'purchase_price' => 100, 'cost_price' => 100, 'track_inventory' => false, 'is_active' => true,
        ]);

        $html = $this->asOwner()->get('/sales/create')->assertOk()->getContent();

        // The foreign barcode is not in this workspace's payload, so a scan of it
        // can never resolve to a product here.
        $this->assertStringContainsString('1111111111111', $html);
        $this->assertStringNotContainsString('9999999999999', $html);
        $this->assertStringContainsString((string) $mine->id, $html);
    }
}