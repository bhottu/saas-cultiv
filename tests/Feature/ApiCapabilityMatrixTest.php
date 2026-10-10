<?php

namespace Tests\Feature;

use App\Models\ApiCapability;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ApiAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * /admin/api capability matrix and the endpoints it gates.
 *
 * The failure this file exists to prevent: the UI must never advertise an operation no
 * endpoint implements, and the server must never honour one the platform switched off.
 * Every assertion is therefore paired — what the checkbox says, and what the guard does.
 *
 * It also pins the three things a client must not be able to talk its way into: claiming
 * a sale is paid, writing into another workspace, or deleting a historical record (a
 * sale, a stock movement, or a received purchase).
 */
class ApiCapabilityMatrixTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private Tenant $tenant;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->owner = User::create([
            'name' => 'Matrix Owner', 'email' => 'matrix-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->admin = User::create([
            'name' => 'Platform Admin', 'email' => 'matrix-admin@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
            'is_platform_admin' => true,
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Matrix Workspace', 'slug' => 'matrix-workspace', 'owner_id' => $this->owner->id,
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->owner->forceFill(['current_tenant_id' => $this->tenant->id])->save();

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Main Warehouse', 'code' => 'MAIN', 'is_active' => true,
        ]);
    }

    private function onPlan(string $slug): void
    {
        $plan = Plan::where('slug', $slug)->firstOrFail();

        Subscription::create([
            'tenant_id' => $this->tenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);
    }

    /** A real Sanctum token carrying `*`, so the capability layer is what is under test. */
    private function token(): string
    {
        return $this->owner->createToken('matrix-integration')->plainTextToken;
    }

    private function send(string $method, string $uri, array $payload = [])
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->json($method, '/api/v1'.$uri, $payload);
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin)->withSession(['tenant_id' => $this->tenant->id]);
    }

    private function makeProduct(array $attrs = []): Product
    {
        $product = Product::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'category_id' => null,
            'brand_id' => null,
            'sku' => 'SKU-'.strtoupper(bin2hex(random_bytes(3))),
            'barcode' => null,
            'name' => 'Monstera Albo',
            'unit' => 'pcs',
            'purchase_price' => 1_000_000,
            'cost_price' => 1_000_000,
            'selling_price' => 1_500_000,
            'minimum_stock' => 5,
            'max_stock' => 100,
            'track_inventory' => true,
            'is_active' => true,
        ], $attrs));

        StockBalance::create([
            'tenant_id' => $this->tenant->id, 'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'incoming' => 0, 'outgoing' => 0,
        ]);

        return $product;
    }

    /**
     * Flip a platform capability for one resource.
     *
     * ApiAccessService is a container singleton with a request-local row cache, so the
     * edit must be flushed or the next HTTP call inside this test would still be served
     * the pre-edit row (which is exactly what the "deny then re-allow" cases below catch).
     */
    private function capability(string $resource, array $flags): void
    {
        ApiCapability::query()->where('resource', $resource)->update($flags);

        app(ApiAccessService::class)->flush();
    }

    /**
     * Give a product real stock.
     *
     * `quantity` is a cache derived as incoming - outgoing by InventoryService, so
     * writing it alone would be silently recomputed away by the very next movement.
     */
    private function seedStock(Product $product, int $quantity): void
    {
        StockBalance::query()->where('product_id', $product->id)->update([
            'incoming' => $quantity,
            'outgoing' => 0,
            'quantity' => $quantity,
        ]);
    }

    private function validSalePayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'warehouse_id' => $this->warehouse->id,
            'sales_channel' => 'pos',
            'discount_type' => 'fixed',
            'discount_value' => 0,
            'payment_method' => 'cash',
            'payment_amount' => 0,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 15_000],
            ],
        ], $overrides);
    }

    /* ------------------------------------------------- 1. checkbox truthfulness */

    public function test_write_checkboxes_exist_for_every_resource_that_has_a_write_endpoint(): void
    {
        $html = $this->asAdmin()->get(route('admin.api.index'))->assertOk()->getContent();

        // The four that were previously hidden behind `write => false` in the registry.
        foreach (['warehouses', 'stock', 'sales', 'purchases'] as $resource) {
            $this->assertStringContainsString(
                "name=\"capabilities[{$resource}][write]\"",
                $html,
                "Expected a Write checkbox for {$resource}."
            );
        }
    }

    public function test_delete_checkboxes_exist_only_where_a_delete_endpoint_exists(): void
    {
        $html = $this->asAdmin()->get(route('admin.api.index'))->assertOk()->getContent();

        foreach (['warehouses', 'purchases'] as $resource) {
            $this->assertStringContainsString("name=\"capabilities[{$resource}][delete]\"", $html);
        }

        // Nothing is routable as a delete for these, so no checkbox is offered at all —
        // rendering a dead switch would claim a capability the API does not have.
        foreach (['sales', 'stock', 'payments', 'products', 'customers'] as $resource) {
            $this->assertStringNotContainsString(
                "name=\"capabilities[{$resource}][delete]\"",
                $html,
                "Must not offer a Delete checkbox for {$resource}: no endpoint exists."
            );
        }
    }

    public function test_the_token_scope_matrix_matches_the_enabled_capabilities(): void
    {
        $access = app(ApiAccessService::class);
        $scopes = $access->availableScopes();

        foreach (['warehouses', 'stock', 'sales', 'purchases'] as $resource) {
            $this->assertContains("{$resource}:write", $scopes);
        }

        $this->assertContains('warehouses:delete', $scopes);
        $this->assertContains('purchases:delete', $scopes);

        // Delete is not even an operation these resources have.
        foreach (['sales', 'stock', 'payments'] as $resource) {
            $this->assertFalse($access->supports($resource, 'delete'), "{$resource} must not support delete.");
            $this->assertNotContains("{$resource}:delete", $scopes);
        }

        // Payments stay read-only in every direction.
        $this->assertFalse($access->supports('payments', 'write'));
        $this->assertNotContains('payments:write', $scopes);
    }

    /* -------------------------------------------------- 2. server-side gating */

    public function test_a_disabled_write_capability_denies_the_endpoint_regardless_of_token_scope(): void
    {
        $this->onPlan('business');
        $this->capability('sales', ['read_enabled' => true, 'write_enabled' => false]);

        $this->send('POST', '/sales', $this->validSalePayload($this->makeProduct()))
            ->assertForbidden()
            ->assertJsonPath('code', 'api_capability_disabled');

        // Reading stays on — the switch is per operation, not per resource.
        $this->send('GET', '/sales')->assertOk();

        $this->capability('sales', ['write_enabled' => true]);

        $stocked = $this->makeProduct();
        $this->seedStock($stocked, 10);

        $this->send('POST', '/sales', $this->validSalePayload($stocked))
            ->assertCreated();
    }

    public function test_a_disabled_delete_capability_denies_the_delete_endpoint(): void
    {
        $this->onPlan('business');
        $this->capability('warehouses', ['delete_enabled' => false]);

        $target = Warehouse::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Disposable', 'code' => 'DSP', 'is_active' => true,
        ]);

        $this->send('DELETE', '/warehouses/'.$target->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'api_capability_disabled');

        $this->assertDatabaseHas('warehouses', ['id' => $target->id, 'deleted_at' => null]);

        $this->capability('warehouses', ['delete_enabled' => true]);

        $this->send('DELETE', '/warehouses/'.$target->id)->assertOk();
        $this->assertDatabaseMissing('warehouses', ['id' => $target->id]);
    }

    public function test_operations_are_refused_without_the_entitlement(): void
    {
        // Free plan has api_access = false: the gate rejects before anything is written.
        $this->send('POST', '/sales', $this->validSalePayload($this->makeProduct()))
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_limit_reached')
            ->assertJsonPath('upgrade_required', true);

        $this->assertSame(0, Sale::query()->count());
    }

    /* ------------------------------------------- 3. creating a sale over the API */

    public function test_creating_a_sale_through_the_api_really_moves_stock(): void
    {
        $this->onPlan('business');

        $product = $this->makeProduct();
        $this->seedStock($product, 10);

        $sale = $this->send('POST', '/sales', $this->validSalePayload($product))
            ->assertCreated()
            ->json('data');

        $this->assertNotNull($sale['id']);

        // 10 on hand, 2 sold — the deduction is the service's, not the client's.
        $this->assertSame(8, (int) StockBalance::query()
            ->where('product_id', $product->id)->value('quantity'));

        $model = Sale::query()->findOrFail($sale['id']);
        $this->assertSame('completed', $model->status);
        // Totals recomputed server-side: 2 x 15.000 rupiah = 30.000 rupiah = 3.000.000 cents.
        $this->assertSame(3_000_000, (int) $model->total);
    }

    public function test_a_client_cannot_claim_a_sale_is_paid(): void
    {
        $this->onPlan('business');

        $product = $this->makeProduct();
        $this->seedStock($product, 10);

        // `payment_status` is not part of the contract at all; a client asserting PAID
        // while paying nothing must not be able to settle its own invoice.
        $sale = $this->send('POST', '/sales', $this->validSalePayload($product, [
            'payment_status' => 'paid',
            'payment_amount' => 0,
            'total' => 0,
        ]))->assertCreated()->json('data');

        $model = Sale::query()->findOrFail($sale['id']);

        $this->assertSame('unpaid', $model->payment_status);
        $this->assertSame(3_000_000, (int) $model->total);
    }

    public function test_a_sale_cannot_be_recorded_into_another_workspace(): void
    {
        $this->onPlan('business');

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-matrix', 'owner_id' => $this->owner->id]);
        $foreignWarehouse = Warehouse::create([
            'tenant_id' => $other->id, 'name' => 'Foreign', 'code' => 'FOR', 'is_active' => true,
        ]);

        $product = $this->makeProduct();

        // warehouse_id is validated against the token's workspace, so a foreign id is a
        // validation failure rather than a cross-tenant write.
        $this->send('POST', '/sales', $this->validSalePayload($product, [
            'warehouse_id' => $foreignWarehouse->id,
        ]))->assertStatus(422);

        $this->assertSame(0, Sale::query()->count());
    }

    /* ------------------------------------------------------- 4. stock adjust */

    public function test_stock_can_only_be_adjusted_through_a_movement(): void
    {
        $this->onPlan('business');

        $product = $this->makeProduct();
        $this->seedStock($product, 4);

        $response = $this->send('POST', '/stock/adjust', [
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => 'in',
            'quantity' => 6,
            'reason' => 'restock',
        ])->assertOk();

        $this->assertSame(10, $response->json('balance'));

        // The quantity is backed by a recorded movement, never a silent overwrite.
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'quantity' => 6,
        ]);

        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
        ]);
    }

    /* -------------------------------------------------- 5. warehouse deletes */

    public function test_a_warehouse_holding_stock_refuses_to_delete(): void
    {
        $this->onPlan('business');

        $this->makeProduct(); // creates a stock balance in this warehouse

        $this->send('DELETE', '/warehouses/'.$this->warehouse->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'warehouse_not_empty');

        $this->assertDatabaseHas('warehouses', ['id' => $this->warehouse->id, 'deleted_at' => null]);
    }

    /* --------------------------------------------------------- 6. purchases */

    public function test_creating_a_purchase_never_adds_stock(): void
    {
        $this->onPlan('business');

        $product = $this->makeProduct();
        $this->seedStock($product, 5);

        $supplier = Supplier::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Sumber Rejeki', 'phone' => '0812', 'is_active' => true,
        ]);

        $purchase = $this->send('POST', '/purchases', [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'discount' => 0,
            'tax' => 0,
            'shipping' => 0,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 5_000],
            ],
        ])->assertCreated()->json('data');

        $this->assertSame('ordered', $purchase['status']);

        // An order is a promise: stock only grows when it is received.
        $this->assertSame(5, (int) StockBalance::query()->where('product_id', $product->id)->value('quantity'));

        // 20 x 5.000 rupiah = 100.000 rupiah = 10.000.000 cents, recomputed server-side.
        $this->assertSame(10_000_000, (int) $purchase['totals']['total']);

        // Received purchases are immutable and non-deletable — their stock already moved.
        $this->send('DELETE', '/purchases/'.$purchase['id'])
            ->assertForbidden()
            ->assertJsonPath('code', 'purchase_not_draft');

        Purchase::query()->where('id', $purchase['id'])->update(['status' => 'draft']);

        $this->send('DELETE', '/purchases/'.$purchase['id'])->assertOk();
        // Soft-deleted: the row stays as history, it is just out of the way.
        $this->assertSoftDeleted('purchases', ['id' => $purchase['id']]);
    }

    /* --------------------------------- 7. no endpoint where none is supported */

    public function test_resources_without_a_delete_endpoint_have_no_delete_route(): void
    {
        $this->onPlan('business');

        // A method the router simply does not know — 405 (or 404 where not even the
        // path exists) rather than a 403 from the guard — is what proves the operation
        // genuinely does not exist in this build, so no checkbox can be honest about it.
        $this->send('DELETE', '/sales/1')->assertStatus(405);
        $this->send('DELETE', '/products/1')->assertStatus(405);
        $this->send('DELETE', '/stock/1')->assertStatus(405);
        $this->send('DELETE', '/payments/1')->assertNotFound();

        // Belt and braces: no DELETE route is registered under any of these resources.
        foreach (['sales', 'products', 'stock', 'payments', 'customers', 'suppliers'] as $resource) {
            $deletes = collect(app('router')->getRoutes()->getRoutes())
                ->filter(fn ($route) => in_array('DELETE', $route->methods(), true)
                    && str_starts_with($route->uri(), "api/v1/{$resource}"));

            $this->assertTrue(
                $deletes->isEmpty(),
                "Unexpected DELETE route registered for {$resource}: ".$deletes->pluck('uri')->implode(', ')
            );
        }
    }

    /* ----------------------------------------- 8. admin save cannot be abused */

    public function test_the_admin_save_cannot_persist_a_stricter_operation_without_the_weaker_ones(): void
    {
        $this->capability('warehouses', ['read_enabled' => false, 'write_enabled' => false, 'delete_enabled' => false]);

        // A hand-crafted payload switches Delete on while leaving Read and Write off.
        $this->asAdmin()->put(route('admin.api.update'), [
            'capabilities' => ['warehouses' => ['read' => 0, 'write' => 0, 'delete' => 1]],
        ])->assertRedirect(route('admin.api.index'));

        $row = ApiCapability::query()->where('resource', 'warehouses')->first();

        $this->assertTrue($row->read_enabled, 'Delete must imply Read.');
        $this->assertTrue($row->write_enabled, 'Delete must imply Write.');
        $this->assertTrue($row->delete_enabled);

        // And a resource with no delete endpoint can never be switched on, even if asked.
        $this->asAdmin()->put(route('admin.api.update'), [
            'capabilities' => ['sales' => ['read' => 1, 'write' => 1, 'delete' => 1]],
        ])->assertRedirect(route('admin.api.index'));

        $sales = ApiCapability::query()->where('resource', 'sales')->first();
        $this->assertFalse($sales->delete_enabled);
        $this->assertFalse(app(ApiAccessService::class)->supports('sales', 'delete'));
    }
}