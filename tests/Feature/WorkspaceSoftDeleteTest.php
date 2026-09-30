<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Workspace deletion is a SOFT delete.
 *
 * Every test here proves one clause of the rule: the row survives, it disappears
 * from active lists, it cannot be used, and the business data it owned is intact.
 */
class WorkspaceSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->owner = User::create([
            'name' => 'WS Owner', 'email' => 'soft-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = $this->makeTenant('Toko ABC', $this->owner);
    }

    private function makeTenant(string $name, User $owner): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(5)),
            'owner_id' => $owner->id,
            'status' => 'active',
        ]);

        $tenant->users()->attach($owner->id, ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]);

        return $tenant;
    }

    private function seedBusinessData(Tenant $tenant): void
    {
        $product = Product::create([
            'tenant_id' => $tenant->id, 'sku' => 'P-1', 'name' => 'Monstera',
            'unit' => 'pcs', 'selling_price' => 1000, 'track_inventory' => true, 'is_active' => true,
        ]);
        Customer::create(['tenant_id' => $tenant->id, 'name' => 'Budi', 'is_active' => true]);
        Supplier::create(['tenant_id' => $tenant->id, 'name' => 'TokoBibit', 'is_active' => true]);
        Warehouse::create(['tenant_id' => $tenant->id, 'name' => 'Gudang', 'code' => 'G1', 'is_active' => true]);
        Sale::create([
            'tenant_id' => $tenant->id, 'invoice_number' => 'INV-1', 'status' => 'completed',
            'payment_status' => 'paid', 'subtotal' => 1000, 'discount' => 0, 'tax' => 0,
            'shipping' => 0, 'total' => 1000, 'total_cogs' => 500, 'sold_at' => now(),
        ]);
    }

    private function deleteWorkspace(Tenant $tenant): void
    {
        $this->actingAs($this->owner)->delete("/tenants/{$tenant->id}")->assertRedirect('/tenants');
    }

    // ------------------------------------------------------------------ Test 1

    public function test_delete_soft_deletes_and_the_row_survives(): void
    {
        $this->seedBusinessData($this->tenant);
        $id = $this->tenant->id;

        $this->deleteWorkspace($this->tenant);

        // The row is still physically present with a deleted_at stamp.
        $this->assertSoftDeleted('tenants', ['id' => $id]);

        $row = \Illuminate\Support\Facades\DB::table('tenants')->where('id', $id)->first();
        $this->assertNotNull($row, 'The workspace row must not be removed from the table.');
        $this->assertNotNull($row->deleted_at);
        $this->assertSame('Toko ABC', $row->name);
    }

    // ------------------------------------------------------------------ Test 2

    public function test_deleted_workspace_disappears_from_the_active_list(): void
    {
        $this->deleteWorkspace($this->tenant);

        $html = $this->actingAs($this->owner)->get('/tenants')->assertOk()->getContent();

        $this->assertStringNotContainsString('Toko ABC', $html);

        // It is also gone from the model layer (SoftDeletes scope).
        $this->assertNull(Tenant::find($this->tenant->id));
        $this->assertNotNull(Tenant::withTrashed()->find($this->tenant->id));
    }

    // ------------------------------------------------------------------ Test 3

    public function test_deleted_workspace_cannot_be_switched_to(): void
    {
        $second = $this->makeTenant('Toko XYZ', $this->owner);
        $this->deleteWorkspace($this->tenant);

        $html = $this->actingAs($this->owner)->get('/tenants')->assertOk()->getContent();
        $this->assertStringNotContainsString(route('tenants.switch', $this->tenant), $html);
        $this->assertStringContainsString(route('tenants.switch', $second), $html);

        // Forcing the switch endpoint directly is refused.
        $this->actingAs($this->owner)->post("/tenants/{$this->tenant->id}/switch")->assertNotFound();
    }

    // ------------------------------------------------------------------ Test 4

    public function test_deleted_workspace_is_unreachable_by_direct_url(): void
    {
        $id = $this->tenant->id;
        $this->deleteWorkspace($this->tenant);

        $this->actingAs($this->owner)->get("/tenants/{$id}/edit")->assertNotFound();
        $this->actingAs($this->owner)->put("/tenants/{$id}", ['name' => 'Dibajak'])->assertNotFound();
        $this->actingAs($this->owner)->delete("/tenants/{$id}")->assertNotFound();
    }

    // ------------------------------------------------------------------ Test 5

    public function test_business_data_survives_the_workspace_deletion(): void
    {
        $this->seedBusinessData($this->tenant);
        $id = $this->tenant->id;

        $this->deleteWorkspace($this->tenant);

        $this->assertDatabaseHas('products', ['tenant_id' => $id]);
        $this->assertDatabaseHas('customers', ['tenant_id' => $id]);
        $this->assertDatabaseHas('suppliers', ['tenant_id' => $id]);
        $this->assertDatabaseHas('warehouses', ['tenant_id' => $id]);
        $this->assertDatabaseHas('sales', ['tenant_id' => $id]);

        // And the retained rows still point at the original workspace.
        $this->assertSame($id, (int) \Illuminate\Support\Facades\DB::table('sales')->value('tenant_id'));
    }


    // ------------------------------------------------------------------ Test 6

    public function test_deleting_the_active_workspace_leaves_the_user_in_a_safe_state(): void
    {
        $second = $this->makeTenant('Toko XYZ', $this->owner);

        // User is switched into the workspace they are about to delete.
        $this->actingAs($this->owner)->post("/tenants/{$this->tenant->id}/switch")->assertRedirect('/dashboard');
        $this->assertSame($this->tenant->id, $this->owner->fresh()->current_tenant_id);

        $this->deleteWorkspace($this->tenant);

        // The stale pointer is cleared instead of leaving a dead 403 behind.
        $this->assertNull($this->owner->fresh()->current_tenant_id);

        // The surviving workspace is still reachable.
        $this->actingAs($this->owner)->post("/tenants/{$second->id}/switch")->assertRedirect('/dashboard');
        $this->assertSame($second->id, $this->owner->fresh()->current_tenant_id);
        $this->actingAs($this->owner)->get('/dashboard')->assertOk();
    }

    // ------------------------------------------------------------------ Test 7

    public function test_soft_deleted_workspace_is_not_counted_against_the_plan_limit(): void
    {
        $usage = app(\App\Services\UsageService::class);

        // Free plan allows exactly one workspace.
        app(\App\Services\SubscriptionService::class)->switchToFree($this->tenant);
        $this->assertSame(1, $usage->workspaceLimitFor($this->owner));

        // 1 active + 1 soft-deleted must still count as 1, so the slot is reusable.
        $this->deleteWorkspace($this->tenant);
        $this->assertSame(0, Tenant::where('owner_id', $this->owner->id)->count());

        $this->actingAs($this->owner)->post('/tenants', ['name' => 'Toko Baru'])
            ->assertRedirect('/dashboard');

        $this->assertDatabaseHas('tenants', ['name' => 'Toko Baru', 'deleted_at' => null]);
    }

    // ------------------------------------------------------------------ purge

    public function test_routine_purge_never_destroys_business_data(): void
    {
        $this->seedBusinessData($this->tenant);
        $id = $this->tenant->id;

        $this->deleteWorkspace($this->tenant);

        // Move it past the retention window.
        Tenant::withTrashed()->where('id', $id)->update([
            'data_retention_until' => now()->subDay(),
        ]);

        $this->artisan('tenants:purge')->assertSuccessful();

        // Business rows are still there, and so is the workspace row itself.
        $this->assertDatabaseHas('products', ['tenant_id' => $id]);
        $this->assertDatabaseHas('customers', ['tenant_id' => $id]);
        $this->assertDatabaseHas('sales', ['tenant_id' => $id]);
        $this->assertDatabaseHas('warehouses', ['tenant_id' => $id]);
        $this->assertDatabaseHas('tenants', ['id' => $id]);
    }

    public function test_retention_window_is_always_respected(): void
    {
        $id = $this->tenant->id;
        $this->deleteWorkspace($this->tenant);

        // Still inside the retention window.
        Tenant::withTrashed()->where('id', $id)->update([
            'data_retention_until' => now()->addDays(20),
        ]);

        $this->artisan('tenants:purge')->assertSuccessful();

        $this->assertDatabaseHas('tenants', ['id' => $id]);
    }
}
