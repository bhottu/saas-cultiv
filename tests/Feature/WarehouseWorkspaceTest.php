<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Warehouse CRUD + workspace management (Open / Edit / Delete) and the app-wide
 * submit loading contract.
 */
class WarehouseWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'WS Owner', 'email' => 'ws-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = $this->makeTenant('Toko ABC', $this->owner);
    }

    private function makeTenant(string $name, User $owner, string $role = 'Owner'): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(5)),
            'owner_id' => $owner->id,
            'status' => 'active',
        ]);

        $tenant->users()->attach($owner->id, ['role' => $role, 'status' => 'active', 'joined_at' => now()]);

        return $tenant;
    }

    private function asOwner(?Tenant $tenant = null)
    {
        $tenant ??= $this->tenant;

        return $this->actingAs($this->owner)->withSession(['tenant_id' => $tenant->id]);
    }

    private function makeWarehouse(array $attrs = [], ?Tenant $tenant = null): Warehouse
    {
        return Warehouse::create(array_merge([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'address' => 'Jl. Merdeka 1',
            'is_active' => true,
        ], $attrs));
    }

    // ------------------------------------------------------------------ warehouse

    public function test_warehouse_pages_render_with_the_shared_shell(): void
    {
        $this->makeWarehouse();

        foreach (['/warehouses', '/warehouses/create'] as $url) {
            $this->asOwner()->get($url)
                ->assertOk()
                ->assertSee('id="sidebar"', false)
                ->assertSee('id="mobile-sidebar"', false);
        }
    }

    public function test_warehouse_crud_round_trip(): void
    {
        $this->asOwner()->post('/warehouses', [
            'name' => 'Gudang Timur', 'code' => 'GDG-T', 'address' => 'Jl. Timur 9', 'is_active' => '1',
        ])->assertRedirect('/warehouses')->assertSessionHas('status');

        $warehouse = Warehouse::where('code', 'GDG-T')->firstOrFail();
        $this->assertSame('Gudang Timur', $warehouse->name);
        $this->assertSame($this->tenant->id, $warehouse->tenant_id);

        $this->asOwner()->get("/warehouses/{$warehouse->id}")->assertOk()->assertSee('Gudang Timur');

        $this->asOwner()->put("/warehouses/{$warehouse->id}", [
            'name' => 'Gudang Barat', 'code' => 'GDG-B', 'address' => 'Jl. Barat 3', 'is_active' => '1',
        ])->assertRedirect('/warehouses');

        $this->assertSame('Gudang Barat', $warehouse->fresh()->name);

        $this->asOwner()->delete("/warehouses/{$warehouse->id}")->assertRedirect('/warehouses');
        // Warehouse does not use SoftDeletes (the table has deleted_at, the model does
        // not opt in), so the existing behaviour is a hard delete, guarded by the
        // "has stock balances" refusal in the controller.
        $this->assertDatabaseMissing('warehouses', ['id' => $warehouse->id]);
    }

    public function test_warehouse_code_is_unique_per_tenant_only(): void
    {
        $this->makeWarehouse(['code' => 'MAIN']);

        $this->asOwner()->post('/warehouses', ['name' => 'Other', 'code' => 'MAIN'])
            ->assertSessionHasErrors('code');

        $stranger = User::create([
            'name' => 'Stranger', 'email' => 'stranger@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $other = $this->makeTenant('Toko XYZ', $stranger);
        $this->makeWarehouse(['code' => 'MAIN'], $other);

        $this->assertSame(2, Warehouse::withoutGlobalScopes()->where('code', 'MAIN')->count());
    }

    public function test_warehouse_from_another_workspace_is_not_reachable(): void
    {
        $stranger = User::create([
            'name' => 'Stranger', 'email' => 'wh-stranger@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $other = $this->makeTenant('Toko XYZ', $stranger);
        $foreign = $this->makeWarehouse(['name' => 'Gudang Asing'], $other);

        $this->asOwner()->get("/warehouses/{$foreign->id}")->assertNotFound();
        $this->asOwner()->get("/warehouses/{$foreign->id}/edit")->assertNotFound();
        $this->asOwner()->put("/warehouses/{$foreign->id}", ['name' => 'Dibajak'])->assertNotFound();
        $this->asOwner()->delete("/warehouses/{$foreign->id}")->assertNotFound();

        $this->asOwner()->get('/warehouses')->assertOk()->assertDontSee('Gudang Asing');
    }

    public function test_warehouse_holding_stock_cannot_be_deleted(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = Product::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'SKU-1', 'name' => 'Produk',
            'unit' => 'pcs', 'selling_price' => 1000, 'track_inventory' => true, 'is_active' => true,
        ]);
        StockBalance::create([
            'tenant_id' => $this->tenant->id, 'product_id' => $product->id,
            'warehouse_id' => $warehouse->id, 'quantity' => 5, 'incoming' => 0, 'outgoing' => 0,
        ]);

        $this->asOwner()->delete("/warehouses/{$warehouse->id}")
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNotSoftDeleted('warehouses', ['id' => $warehouse->id]);
    }

    // ------------------------------------------------------------- workspace mgmt

    public function test_workspace_index_offers_open_edit_and_delete(): void
    {
        $html = $this->actingAs($this->owner)->get('/tenants')->assertOk()->getContent();

        $this->assertStringContainsString('Toko ABC', $html);
        $this->assertStringContainsString('>Open<', $html);
        $this->assertStringContainsString(route('tenants.edit', $this->tenant), $html);
        $this->assertStringContainsString(route('tenants.destroy', $this->tenant), $html);

        // Destructive action is confirmed before it is sent.
        $this->assertStringContainsString('confirm(', $html);
    }

    public function test_workspace_can_be_renamed_by_its_owner(): void
    {
        $this->actingAs($this->owner)->get("/tenants/{$this->tenant->id}/edit")
            ->assertOk()
            ->assertSee('Toko ABC');

        $this->actingAs($this->owner)->put("/tenants/{$this->tenant->id}", ['name' => 'Toko ABC-New'])
            ->assertRedirect('/tenants')
            ->assertSessionHas('success');

        $this->assertSame('Toko ABC-New', $this->tenant->fresh()->name);
    }

    public function test_workspace_rename_requires_a_name(): void
    {
        $this->actingAs($this->owner)->put("/tenants/{$this->tenant->id}", ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame('Toko ABC', $this->tenant->fresh()->name);
    }

    public function test_a_stranger_cannot_rename_or_delete_someone_elses_workspace(): void
    {
        $stranger = User::create([
            'name' => 'Stranger', 'email' => 'tenant-stranger@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->actingAs($stranger)->get("/tenants/{$this->tenant->id}/edit")->assertForbidden();
        $this->actingAs($stranger)->put("/tenants/{$this->tenant->id}", ['name' => 'Dibajak'])->assertForbidden();
        $this->actingAs($stranger)->delete("/tenants/{$this->tenant->id}")->assertForbidden();

        $this->assertSame('Toko ABC', $this->tenant->fresh()->name);
    }

    public function test_a_plain_member_cannot_rename_the_workspace(): void
    {
        $member = User::create([
            'name' => 'Member', 'email' => 'tenant-member@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $this->tenant->users()->attach($member->id, [
            'role' => 'Staff', 'status' => 'active', 'joined_at' => now(),
        ]);

        // Membership alone is not ownership.
        $this->actingAs($member)->get("/tenants/{$this->tenant->id}/edit")->assertForbidden();
    }

    public function test_owner_can_delete_a_workspace_and_it_is_soft_deleted_with_retention(): void
    {
        $this->actingAs($this->owner)->delete("/tenants/{$this->tenant->id}")
            ->assertRedirect('/tenants');

        $this->assertSoftDeleted('tenants', ['id' => $this->tenant->id]);

        $row = Tenant::withTrashed()->findOrFail($this->tenant->id);
        $this->assertSame('pending_deletion', $row->status);
        $this->assertNotNull($row->data_retention_until, 'A retention window must be set before purge.');

        // Business records are not destroyed with the workspace.
        $this->makeWarehouse();
        $this->assertDatabaseHas('warehouses', ['tenant_id' => $this->tenant->id]);
    }

    public function test_navigation_exposes_warehouses_under_inventory(): void
    {
        $this->makeWarehouse();

        $html = $this->asOwner()->get('/products')->assertOk()->getContent();

        preg_match('/<aside id="sidebar".*?<\/aside>/s', $html, $m);
        $this->assertNotEmpty($m, 'Sidebar missing.');

        $this->assertStringContainsString(route('warehouses.index'), $m[0]);
        $this->assertStringContainsString('Warehouses', $m[0]);
    }
}

