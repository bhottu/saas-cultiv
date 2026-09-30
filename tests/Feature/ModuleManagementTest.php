<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module management (marketplace + per-workspace activation) and the POS terminal.
 *
 * The two-tier rule is the point of these tests:
 *  - AVAILABILITY is per workspace (tenant_modules), enforced by the `module` middleware.
 *  - ACCESS is per user (the existing business permission), enforced by the controller.
 *  Activating a module must never grant a user the right to use it, and holding the
 *  permission must never expose a module the workspace did not activate.
 */
class ModuleManagementTest extends TestCase
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

        $this->owner = $this->makeUser('module-owner@test.dev');
        $this->otherOwner = $this->makeUser('module-other@test.dev');

        $this->tenant = $this->makeTenant('Toko Modul', $this->owner);
        $this->otherTenant = $this->makeTenant('Toko Lain', $this->otherOwner);

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Gudang Utama',
            'code'      => 'MAIN',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'tenant_id'       => $this->tenant->id,
            'name'            => 'Kopi Susu',
            'sku'             => 'KS-001',
            'barcode'         => '8991234567890',
            'unit'            => 'pcs',
            'purchase_price'  => 1_000_000,  // Rp 10.000
            'selling_price'   => 1_500_000,  // Rp 15.000
            'cost_price'      => 1_000_000,
            'track_inventory' => true,
            'is_active'       => true,
        ]);

        StockBalance::create([
            'tenant_id'    => $this->tenant->id,
            'product_id'   => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity'     => 50,
            'incoming'     => 50,
            'outgoing'     => 0,
        ]);

        // The platform catalogue is seeded from config/modules.php by ModuleSeeder.
        $this->seed(\Database\Seeders\ModuleSeeder::class);
    }

    // ---------------------------------------------------------------- helpers

    private function makeUser(string $email): User
    {
        return User::create([
            'name' => 'User ' . Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }

    private function makeTenant(string $name, User $owner, string $role = 'Owner'): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'owner_id' => $owner->id,
            'status' => 'active',
        ]);

        $tenant->users()->attach($owner->id, ['role' => $role, 'status' => 'active', 'joined_at' => now()]);

        return $tenant;
    }

    private function asOwner(?Tenant $tenant = null)
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => ($tenant ?? $this->tenant)->id]);
    }

    private function posModule(): Module
    {
        return Module::where('key', 'pos')->firstOrFail();
    }

    /** Install + activate POS for a workspace, as the Module Center would. */
    private function activatePos(Tenant $tenant): TenantModule
    {
        $module = $this->posModule();

        return TenantModule::create([
            'tenant_id'    => $tenant->id,
            'module_id'    => $module->id,
            'status'       => TenantModule::STATUS_ACTIVE,
            'installed_at' => now(),
            'activated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------- catalogue

    public function test_module_catalogue_is_platform_owned_and_seeded_from_config(): void
    {
        $module = $this->posModule();

        $this->assertSame('Point of Sale (POS)', $module->name);
        $this->assertSame('pos.index', $module->route);
        $this->assertSame('sales.create', $module->permission);
        $this->assertFalse($module->isPaid());
        $this->assertSame('Free', $module->priceLabel());

        // The catalogue carries no tenant_id: a module definition belongs to the
        // platform, exactly like a Plan.
        $this->assertArrayNotHasKey('tenant_id', $module->getAttributes());
    }

    public function test_workspace_sees_the_marketplace_and_can_install_a_module(): void
    {
        $this->asOwner()
            ->get('/modules')
            ->assertOk()
            ->assertSee('Point of Sale (POS)');

        $this->asOwner()
            ->post('/modules/pos/install')
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_modules', [
            'tenant_id' => $this->tenant->id,
            'status'    => TenantModule::STATUS_INSTALLED,
        ]);
    }

    public function test_installing_twice_does_not_create_a_second_install_row(): void
    {
        $this->asOwner()->post('/modules/pos/install');
        $this->asOwner()->post('/modules/pos/install');

        // The unique(tenant_id, module_id) index is the concurrency guard.
        $this->assertSame(1, TenantModule::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->count());
    }

    // ---------------------------------------------------------------- activation gate

    public function test_pos_route_is_closed_until_the_workspace_activates_the_module(): void
    {
        $this->asOwner()->get('/pos')->assertRedirect();

        // Installed but not activated is still closed.
        TenantModule::create([
            'tenant_id'    => $this->tenant->id,
            'module_id'    => $this->posModule()->id,
            'status'       => TenantModule::STATUS_INSTALLED,
            'installed_at' => now(),
        ]);

        $this->asOwner()->get('/pos')->assertRedirect();
    }

    public function test_pos_opens_once_the_module_is_active(): void
    {
        $this->activatePos($this->tenant);

        $this->asOwner()
            ->get('/pos')
            ->assertOk()
            ->assertSee('Point of Sale');
    }

    public function test_deactivating_the_module_closes_the_route_again(): void
    {
        $install = $this->activatePos($this->tenant);

        $this->asOwner()->get('/pos')->assertOk();

        $this->asOwner()
            ->post('/modules/pos/deactivate')
            ->assertRedirect();

        $this->assertSame(TenantModule::STATUS_INACTIVE, $install->fresh()->status);
        $this->asOwner()->get('/pos')->assertRedirect();
    }

    public function test_activating_a_module_in_one_workspace_does_not_activate_it_in_another(): void
    {
        $this->activatePos($this->tenant);

        $this->assertSame(0, TenantModule::withoutGlobalScopes()
            ->where('tenant_id', $this->otherTenant->id)->count());

        $this->asOwner($this->otherTenant)->get('/pos')->assertRedirect();
    }

    public function test_a_module_in_another_workspace_cannot_be_activated_by_id(): void
    {
        $install = $this->activatePos($this->tenant);

        // The route binds by slug, and the write is scoped to the ACTIVE tenant only.
        $this->asOwner($this->otherTenant)->post('/modules/pos/deactivate');

        $this->assertSame(TenantModule::STATUS_ACTIVE, $install->fresh()->status);
    }


    // ---------------------------------------------------------------- navigation

    /** The rendered desktop sidebar, so assertions cannot match a link in the body. */
    private function sidebar(): string
    {
        $html = $this->asOwner()->get('/dashboard')->assertOk()->getContent();

        preg_match('/<aside id="sidebar".*?<\/aside>/s', $html, $matches);

        $this->assertNotEmpty($matches, 'The shared shell sidebar is missing on /dashboard.');

        return $matches[0];
    }

    public function test_sidebar_links_the_module_only_while_it_is_active(): void
    {
        $this->assertStringNotContainsString('href="'.route('pos.index').'"', $this->sidebar());

        $this->activatePos($this->tenant);

        $this->assertStringContainsString('href="'.route('pos.index').'"', $this->sidebar());
    }

    // ---------------------------------------------------------------- POS checkout

    public function test_pos_checkout_records_a_real_sale_and_deducts_stock(): void
    {
        $this->activatePos($this->tenant);

        $response = $this->asOwner()->postJson('/pos/checkout', [
            'warehouse_id'     => $this->warehouse->id,
            'discount_type'    => 'fixed',
            'discount_value'   => 0,
            'tax_percent'      => 0,
            'payment_method'   => 'cash',
            'payment_amount'   => 50000,      // Rp 50.000
            'client_reference' => 'pos-test-1',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 15000],
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true, 'replayed' => false]);

        $sale = Sale::withoutGlobalScopes()->firstOrFail();

        // 2 x Rp 15.000 = Rp 30.000 stored as 3_000_000 cents.
        $this->assertSame(3_000_000, $sale->total);
        $this->assertSame(5_000_000, $sale->paid_amount);   // Rp 50.000
        $this->assertSame(2_000_000, $sale->change_amount); // Rp 20.000
        $this->assertSame('pos', $sale->sales_channel);
        $this->assertSame(Sale::STATUS_COMPLETED, $sale->status);

        $this->assertSame(48, (int) StockBalance::withoutGlobalScopes()
            ->where('product_id', $this->product->id)->value('quantity'));
    }

    public function test_pos_checkout_is_idempotent_for_a_repeated_client_reference(): void
    {
        $this->activatePos($this->tenant);

        $payload = [
            'warehouse_id'     => $this->warehouse->id,
            'discount_type'    => 'fixed',
            'payment_method'   => 'cash',
            'payment_amount'   => 50000,
            'client_reference' => 'pos-double-tap',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 15000],
            ],
        ];

        $this->asOwner()->postJson('/pos/checkout', $payload)->assertOk();

        $second = $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertOk()
            ->assertJson(['success' => true, 'replayed' => true]);

        // One sale, and stock deducted exactly once.
        $this->assertSame(1, Sale::withoutGlobalScopes()->count());
        $this->assertSame(48, (int) StockBalance::withoutGlobalScopes()
            ->where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(
            Sale::withoutGlobalScopes()->firstOrFail()->id,
            $second->json('sale_id')
        );
    }


    public function test_pos_checkout_refuses_to_sell_more_than_the_warehouse_holds(): void
    {
        $this->activatePos($this->tenant);

        $this->asOwner()->postJson('/pos/checkout', [
            'warehouse_id'     => $this->warehouse->id,
            'discount_type'    => 'fixed',
            'payment_method'   => 'cash',
            'payment_amount'   => 9999999,
            'client_reference' => 'pos-oversell',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 500, 'unit_price' => 15000],
            ],
        ])->assertStatus(422)->assertJson(['success' => false]);

        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
        $this->assertSame(50, (int) StockBalance::withoutGlobalScopes()
            ->where('product_id', $this->product->id)->value('quantity'));
    }

    public function test_pos_checkout_cannot_sell_another_workspaces_product(): void
    {
        $this->activatePos($this->tenant);

        $foreign = Product::create([
            'tenant_id'     => $this->otherTenant->id,
            'name'          => 'Produk Asing',
            'sku'           => 'ASING-1',
            'selling_price' => 1_000_000,
            'track_inventory' => false,
            'is_active'     => true,
        ]);

        $this->asOwner()->postJson('/pos/checkout', [
            'warehouse_id'   => $this->warehouse->id,
            'discount_type'  => 'fixed',
            'payment_method' => 'cash',
            'payment_amount' => 20000,
            'items' => [
                ['product_id' => $foreign->id, 'quantity' => 1, 'unit_price' => 10000],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
    }

    public function test_pos_preview_matches_the_total_that_checkout_persists(): void
    {
        $this->activatePos($this->tenant);

        $preview = $this->asOwner()->postJson('/pos/calculate', [
            'discount_type'  => 'fixed',
            'discount_value' => 5000,   // Rp 5.000 off
            'tax_percent'    => 10,     // 10% on the discounted amount
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 15000],
            ],
        ])->assertOk();

        // Subtotal 30.000 - 5.000 = 25.000, +10% tax = 27.500 => 2_750_000 cents.
        $this->assertSame(3_000_000, $preview->json('totals.subtotal'));
        $this->assertSame(500_000, $preview->json('totals.discount'));
        $this->assertSame(250_000, $preview->json('totals.tax'));
        $this->assertSame(2_750_000, $preview->json('totals.total'));

        $this->asOwner()->postJson('/pos/checkout', [
            'warehouse_id'     => $this->warehouse->id,
            'discount_type'    => 'fixed',
            'discount_value'   => 5000,
            'tax_percent'      => 10,
            'payment_method'   => 'cash',
            'payment_amount'   => 27500,
            'client_reference' => 'pos-preview-match',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 15000],
            ],
        ])->assertOk();

        $this->assertSame(
            $preview->json('totals.total'),
            Sale::withoutGlobalScopes()->firstOrFail()->total
        );
    }

    public function test_pos_products_endpoint_reports_warehouse_stock_in_rupiah(): void
    {
        $this->activatePos($this->tenant);

        $this->asOwner()->getJson('/pos/products?warehouse_id='.$this->warehouse->id)
            ->assertOk()
            ->assertJsonPath('products.0.id', $this->product->id)
            ->assertJsonPath('products.0.stock', 50)
            ->assertJsonPath('products.0.price', 1_500_000)   // cents
            ->assertJsonPath('products.0.price_formatted', 'Rp 15.000');
    }
}
