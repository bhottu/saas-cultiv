<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * End-to-end coverage for the tenant-scoped product catalogue:
 * listing, create/store (money conversion), read/update, guarded delete,
 * role gating and cross-tenant isolation.
 */
class ProductCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $otherOwner;
    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->otherOwner] = collect(['owner@test.dev', 'other@test.dev'])
            ->map(fn ($email) => User::create([
                'name' => 'Owner '.strtoupper($email[0]),
                'email' => $email,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]))->all();

        foreach ([$this->owner, $this->otherOwner] as $index => $owner) {
            $tenant = Tenant::create([
                'name' => 'Tenant '.($index + 1),
                'slug' => 'tenant-'.($index + 1),
                'owner_id' => $owner->id,
            ]);

            $tenant->users()->attach($owner->id, [
                'role' => 'Owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $index === 0 ? $this->tenant = $tenant : $this->otherTenant = $tenant;
        }
    }

    /** Authenticate as a member of $tenant for the current request. */
    private function asMember(Tenant $tenant, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)->withSession(['tenant_id' => $tenant->id]);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kopi Susu',
            'sku' => 'KP-001',
            'unit' => 'pcs',
            'purchase_price' => 1_000_000,
            'selling_price' => 1_500_000,
            'cost_price' => 1_000_000,
            'is_active' => true,
        ], $attributes));
    }

    public function test_products_index_lists_only_current_tenant_products(): void
    {
        $this->makeProduct();
        $this->makeProduct([
            'tenant_id' => $this->otherTenant->id,
            'name' => 'Produk Tenant Lain',
            'sku' => 'KP-999',
        ]);

        $this->asMember($this->tenant)->get('/products')
            ->assertOk()
            ->assertSee('Kopi Susu')
            ->assertDontSee('Produk Tenant Lain');
    }

    public function test_create_form_renders(): void
    {
        $this->asMember($this->tenant)->get('/products/create')
            ->assertOk()
            ->assertSee('Add Product');
    }

    public function test_store_creates_product_and_converts_money_to_cents(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Minuman']);
        $brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'Lokal']);

        $response = $this->asMember($this->tenant)->post('/products', [
            'name' => 'Teh Manis',
            'sku' => 'TM-001',
            'barcode' => '8991234567890',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit' => 'pcs',
            'purchase_price' => '10000',
            'selling_price' => '15000',
            'minimum_stock' => '5',
            'track_inventory' => '1',
            'is_active' => '1',
        ]);

        $product = Product::withoutGlobalScopes()->where('sku', 'TM-001')->firstOrFail();

        $response->assertRedirect(route('products.show', $product));
        $this->assertSame($this->tenant->id, $product->tenant_id);
        $this->assertSame(1_000_000, $product->purchase_price);
        $this->assertSame(1_500_000, $product->selling_price);
        $this->assertSame(1_000_000, $product->cost_price); // falls back to purchase price
        $this->assertSame(5, $product->minimum_stock);
        $this->assertTrue($product->track_inventory);
        $this->assertTrue($product->is_active);
    }

    public function test_sku_must_be_unique_per_tenant_but_may_repeat_across_tenants(): void
    {
        $this->makeProduct(['sku' => 'DUP-1']);

        $this->asMember($this->tenant)
            ->post('/products', ['name' => 'Duplikat', 'unit' => 'pcs', 'sku' => 'DUP-1'])
            ->assertSessionHasErrors('sku');

        $this->asMember($this->otherTenant, $this->otherOwner)
            ->post('/products', ['name' => 'Duplikat', 'unit' => 'pcs', 'sku' => 'DUP-1'])
            ->assertSessionHasNoErrors();
    }

    public function test_category_and_brand_from_another_tenant_are_rejected(): void
    {
        $foreignCategory = Category::create(['tenant_id' => $this->otherTenant->id, 'name' => 'Foreign']);
        $foreignBrand = Brand::create(['tenant_id' => $this->otherTenant->id, 'name' => 'Foreign']);

        $this->asMember($this->tenant)->post('/products', [
            'name' => 'Salah Kategori',
            'unit' => 'pcs',
            'category_id' => $foreignCategory->id,
            'brand_id' => $foreignBrand->id,
        ])->assertSessionHasErrors(['category_id', 'brand_id']);
    }

    public function test_show_and_edit_render_and_update_persists_changes(): void
    {
        $product = $this->makeProduct();

        $this->asMember($this->tenant)->get("/products/{$product->id}")
            ->assertOk()
            ->assertSee('Kopi Susu');

        $this->asMember($this->tenant)->get("/products/{$product->id}/edit")
            ->assertOk()
            ->assertSee('Edit Product');

        $this->asMember($this->tenant)->put("/products/{$product->id}", [
            'name' => 'Kopi Susu Gula Aren',
            'sku' => 'KP-001',
            'unit' => 'pcs',
            'selling_price' => '20000',
            'track_inventory' => '1',
            'is_active' => '1',
        ])->assertRedirect(route('products.show', $product));

        $product->refresh();
        $this->assertSame('Kopi Susu Gula Aren', $product->name);
        $this->assertSame(2_000_000, $product->selling_price);
    }

    public function test_destroy_is_blocked_while_stock_on_hand_and_allowed_when_empty(): void
    {
        $product = $this->makeProduct();

        $warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'name' => 'Gudang Utama']);

        StockBalance::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 7,
            'incoming' => 7,
            'outgoing' => 0,
        ]);

        $this->asMember($this->tenant)->delete("/products/{$product->id}")->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $product->id]);

        $product->stockBalances()->update(['quantity' => 0]);

        $this->asMember($this->tenant)->delete("/products/{$product->id}")
            ->assertRedirect(route('products.index'));
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_show_renders_stock_on_hand_and_movements(): void
    {
        $product = $this->makeProduct();
        $warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'name' => 'Gudang Utama']);

        StockBalance::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 12,
            'incoming' => 20,
            'outgoing' => 8,
        ]);

        StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'in',
            'quantity' => 20,
            'notes' => 'Initial receipt',
            'created_by' => $this->owner->id,
        ]);

        $this->asMember($this->tenant)->get("/products/{$product->id}")
            ->assertOk()
            ->assertSee('Kopi Susu')
            ->assertSee('Gudang Utama')
            ->assertSee('Initial receipt');
    }

    public function test_viewer_can_read_but_not_write_products(): void
    {
        $product = $this->makeProduct();

        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $this->tenant->users()->attach($viewer->id, [
            'role' => 'Viewer',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->asMember($this->tenant, $viewer)->get('/products')->assertOk();
        $this->asMember($this->tenant, $viewer)->get("/products/{$product->id}")->assertOk();

        $this->asMember($this->tenant, $viewer)
            ->post('/products', ['name' => 'Nope', 'unit' => 'pcs'])
            ->assertForbidden();

        $this->asMember($this->tenant, $viewer)
            ->delete("/products/{$product->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_other_tenant_cannot_access_or_modify_product(): void
    {
        $product = $this->makeProduct();

        $this->asMember($this->otherTenant, $this->otherOwner)
            ->get("/products/{$product->id}")->assertNotFound();

        $this->asMember($this->otherTenant, $this->otherOwner)
            ->get("/products/{$product->id}/edit")->assertNotFound();

        $this->asMember($this->otherTenant, $this->otherOwner)
            ->put("/products/{$product->id}", ['name' => 'Hijacked', 'unit' => 'pcs'])
            ->assertNotFound();

        $this->asMember($this->otherTenant, $this->otherOwner)
            ->delete("/products/{$product->id}")->assertNotFound();

        $this->assertSame('Kopi Susu', $product->fresh()->name);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/products')->assertRedirect('/login');
    }

    /**
     * Regression: the index filters used Illuminate\Http\Request::getString(), which does
     * not exist, so any filtered request to /products failed with a BadMethodCallException.
     */
    public function test_index_search_and_category_filters_work(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Minuman']);
        $brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'Kopi Nusantara']);

        $this->makeProduct(['category_id' => $category->id, 'brand_id' => $brand->id]);
        $this->makeProduct(['name' => 'Teh Manis', 'sku' => 'TH-001']);

        $this->asMember($this->tenant)->get('/products?search=Kopi')
            ->assertOk()
            ->assertSee('Kopi Susu')
            ->assertDontSee('Teh Manis');

        $this->asMember($this->tenant)->get('/products?category_id='.$category->id)
            ->assertOk()
            ->assertSee('Kopi Susu')
            ->assertDontSee('Teh Manis');

        $this->asMember($this->tenant)->get('/products?brand_id='.$brand->id)
            ->assertOk()
            ->assertSee('Kopi Susu')
            ->assertDontSee('Teh Manis');

        $this->asMember($this->tenant)->get('/products?active_only=1')
            ->assertOk()
            ->assertSee('Kopi Susu');
    }
}