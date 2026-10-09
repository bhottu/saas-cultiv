<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Public business API: entitlement gate, tenant isolation, RBAC, pagination and
 * the money contract. Uses real Sanctum tokens so the whole auth path is exercised.
 */
class BusinessApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->owner = User::create([
            'name' => 'API Owner', 'email' => 'api-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'API Workspace', 'slug' => 'api-workspace', 'owner_id' => $this->owner->id,
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

    private function token(User $user = null): string
    {
        return ($user ?? $this->owner)->createToken('pos-integration')->plainTextToken;
    }

    private function api(string $uri, array $headers = [])
    {
        return $this->withHeaders($headers + ['Authorization' => 'Bearer '.$this->token()])
            ->getJson('/api/v1'.$uri);
    }

    private function makeProduct(array $attrs = [], Tenant $tenant = null): Product
    {
        $tenant ??= $this->tenant;

        $product = Product::create(array_merge([
            'tenant_id' => $tenant->id,
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
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'incoming' => 0, 'outgoing' => 0,
        ]);

        return $product;
    }

    public function test_api_is_refused_without_the_entitlement(): void
    {
        $this->makeProduct();

        // Free plan has api_access = false, so the gate rejects before any query runs.
        $this->api('/products')
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_limit_reached')
            ->assertJsonPath('upgrade_required', true);
    }

    public function test_business_plan_can_read_the_catalogue(): void
    {
        $this->onPlan('business');

        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Plants', 'slug' => 'plants', 'is_active' => true]);
        $product = $this->makeProduct(['category_id' => $category->id, 'name' => 'Monstera Albo', 'barcode' => '8991234567890']);

        $response = $this->api('/products')->assertOk();

        $response->assertJsonPath('meta.currency', 'IDR')
            ->assertJsonPath('meta.money_unit', 'cents')
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 1);

        $this->assertSame('Monstera Albo', $response->json('data.0.name'));
        $this->assertSame('8991234567890', $response->json('data.0.barcode'));
        $this->assertSame(1_500_000, $response->json('data.0.prices.selling_price'));
        $this->assertSame('Plants', $response->json('data.0.category.name'));
        $this->assertSame($product->id, $response->json('data.0.id'));
    }

    public function test_pro_plan_can_read_the_catalogue(): void
    {
        $this->onPlan('pro');
        $this->makeProduct();

        // Pro is a paid plan, so API Access is included (Free remains the only tier
        // refused above).
        $this->api('/products')->assertOk();
    }

    public function test_api_never_exposes_another_workspaces_data(): void
    {
        $this->onPlan('business');

        $stranger = User::create([
            'name' => 'Foreign Owner', 'email' => 'foreign-api@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $other = Tenant::create([
            'name' => 'Foreign Workspace', 'slug' => 'foreign-workspace', 'owner_id' => $stranger->id,
        ]);
        $other->users()->attach($stranger->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        $mine = $this->makeProduct(['name' => 'Mine']);
        $theirs = $this->makeProduct(['name' => 'Theirs'], $other);

        $list = $this->api('/products')->assertOk();
        $names = collect($list->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Mine'));
        $this->assertFalse($names->contains('Theirs'), 'Foreign product leaked into the listing.');

        // Direct id access is a 404, never a 200.
        $this->api('/products/'.$theirs->id)->assertNotFound();
        $this->api('/products/'.$mine->id)->assertOk()->assertJsonPath('data.name', 'Mine');
    }

    public function test_pagination_is_bounded(): void
    {
        $this->onPlan('business');

        $this->makeProduct(['name' => 'One']);
        $this->makeProduct(['name' => 'Two']);
        $this->makeProduct(['name' => 'Three']);

        $response = $this->api('/products?per_page=2')->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertSame(3, $response->json('meta.total'));

        // A client cannot request the entire table in one call.
        $this->api('/products?per_page=100000')->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_barcode_and_sku_lookup(): void
    {
        $this->onPlan('business');
        $product = $this->makeProduct(['barcode' => '8991234567890', 'sku' => 'MON-ALBO']);

        $this->api('/products/lookup?code=8991234567890')
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.stock.on_hand', 0);

        $this->api('/products/lookup?code=MON-ALBO')
            ->assertOk()
            ->assertJsonPath('data.id', $product->id);

        $this->api('/products/lookup?code=0000000000000')
            ->assertNotFound()
            ->assertJsonPath('code', 'product_not_found');

        $this->api('/products/lookup')->assertStatus(422);
    }

    public function test_stock_summary_flags_low_stock_against_the_product_threshold(): void
    {
        $this->onPlan('business');

        $low = $this->makeProduct(['name' => 'Low Stock Plant', 'minimum_stock' => 10]);
        $rich = $this->makeProduct(['name' => 'Rich Plant', 'minimum_stock' => 10]);

        $low->stockBalances()->update(['quantity' => 3]);
        $rich->stockBalances()->update(['quantity' => 50]);

        $rows = collect($this->api('/stock/summary')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(3, $rows['Low Stock Plant']['quantity_on_hand']);
        $this->assertTrue($rows['Low Stock Plant']['is_low_stock']);
        $this->assertSame(50, $rows['Rich Plant']['quantity_on_hand']);
        $this->assertFalse($rows['Rich Plant']['is_low_stock']);
    }

    public function test_customers_and_suppliers_are_scoped_and_filterable(): void
    {
        $this->onPlan('business');

        Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Budi', 'phone' => '0812', 'is_active' => true]);
        Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Sari', 'phone' => '0813', 'is_active' => false]);

        $list = $this->api('/customers?search=Budi')->assertOk();
        $this->assertSame(['Budi'], collect($list->json('data'))->pluck('name')->all());

        $inactive = $this->api('/customers?inactive_only=1')->assertOk();
        $this->assertSame(['Sari'], collect($inactive->json('data'))->pluck('name')->all());

        $this->api('/suppliers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_warehouses_endpoint_returns_the_workspace_list(): void
    {
        $this->onPlan('business');

        $this->api('/warehouses')->assertOk()
            ->assertJsonPath('data.0.name', 'Main Warehouse')
            ->assertJsonPath('data.0.code', 'MAIN');
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->onPlan('business');

        $this->getJson('/api/v1/products')->assertUnauthorized();
    }
}