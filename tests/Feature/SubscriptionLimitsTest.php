<?php

namespace Tests\Feature;

use App\Exceptions\SubscriptionLimitException;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\UsageService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_the_four_final_plans_are_active_and_have_the_requested_entitlements(): void
    {
        $this->assertSame(['free', 'starter', 'pro', 'business'], Plan::active()->pluck('slug')->all());
        $plans = Plan::active()->get()->keyBy('slug');
        $this->assertSame([1, 1, 100, null], [
            $plans['free']->limit('max_workspaces'), $plans['free']->limit('max_users'),
            $plans['free']->limit('max_products'), $plans['free']->limit('max_customers'),
        ]);
        $this->assertSame([3, 5, null], [$plans['starter']->limit('max_workspaces'), $plans['starter']->limit('max_users'), $plans['starter']->limit('max_products')]);
        $this->assertSame([10, 15], [$plans['pro']->limit('max_workspaces'), $plans['pro']->limit('max_users')]);
        $this->assertNull($plans['business']->limit('max_workspaces'));
        $this->assertSame(50, $plans['business']->limit('max_users'));
        $this->assertFalse($plans['starter']->allows('advanced_reports'));
        $this->assertTrue($plans['pro']->allows('advanced_reports'));
        $this->assertTrue($plans['pro']->allows('advanced_permissions'));
        $this->assertTrue($plans['pro']->allows('audit_log'));
        $this->assertTrue($plans['pro']->allows('advanced_analytics'));
        $this->assertFalse($plans['pro']->allows('api_access'));
        $this->assertTrue($plans['business']->allows('api_access'));
    }

    public function test_free_workspace_creation_is_blocked_in_the_browser_without_deleting_existing_data(): void
    {
        $user = $this->user('free-owner');
        $tenant = $this->tenantFor($user, Plan::where('slug', 'free')->firstOrFail(), 'free-workspace');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Referer', url('/tenants'))
            ->post('/tenants', ['name' => 'Second Workspace'])
            ->assertRedirect('/tenants')
            ->assertSessionHasErrors('name')
            ->assertSessionHas('upgrade_required');

        $this->assertSame(1, $user->ownedTenants()->count());
    }

    public function test_starter_allows_three_workspaces_and_business_is_unlimited(): void
    {
        $usage = app(UsageService::class);
        $starterOwner = $this->user('starter-owner');
        $starter = Plan::where('slug', 'starter')->firstOrFail();
        $this->tenantFor($starterOwner, $starter, 'starter-one');
        $this->tenantFor($starterOwner, $starter, 'starter-two');
        $usage->enforceWorkspaceCreation($starterOwner);
        $this->tenantFor($starterOwner, $starter, 'starter-three');
        $this->expectException(SubscriptionLimitException::class);
        $usage->enforceWorkspaceCreation($starterOwner);

        $businessOwner = $this->user('business-owner');
        $business = Plan::where('slug', 'business')->firstOrFail();
        for ($i = 1; $i <= 12; $i++) {
            $this->tenantFor($businessOwner, $business, 'business-'.$i);
        }
        $usage->enforceWorkspaceCreation($businessOwner);
        $this->assertNull($usage->workspaceLimitFor($businessOwner));
    }

    public function test_free_user_limit_is_backend_enforced(): void
    {
        $usage = app(UsageService::class);
        $owner = $this->user('seat-limit-owner');
        $plan = Plan::where('slug', 'free')->firstOrFail();
        $tenant = $this->tenantFor($owner, $plan, 'seat-limit-workspace');

        $this->expectException(SubscriptionLimitException::class);
        $usage->enforceSeat($tenant);
    }

    public function test_free_product_limit_is_backend_enforced(): void
    {
        $usage = app(UsageService::class);
        $owner = $this->user('product-owner');
        $plan = Plan::where('slug', 'free')->firstOrFail();
        $productTenant = $this->tenantFor($owner, $plan, 'product-workspace');
        for ($i = 1; $i <= 100; $i++) {
            Product::withoutGlobalScopes()->create([
                'tenant_id' => $productTenant->id, 'name' => 'Product '.$i,
                'sku' => 'P-'.$i, 'unit' => 'pcs', 'track_inventory' => false,
                'is_active' => true, 'purchase_price' => 0, 'selling_price' => 0, 'cost_price' => 0,
            ]);
        }

        $this->expectException(SubscriptionLimitException::class);
        $usage->enforce($productTenant, 'max_products');
    }

    public function test_api_access_is_business_only(): void
    {
        $owner = $this->user('api-owner');
        $free = $this->tenantFor($owner, Plan::where('slug', 'free')->firstOrFail(), 'api-free');
        $owner->forceFill(['current_tenant_id' => $free->id])->save();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('code', 'subscription_limit_reached');

        $pro = $this->tenantFor($owner, Plan::where('slug', 'pro')->firstOrFail(), 'api-pro');
        $owner->forceFill(['current_tenant_id' => $pro->id])->save();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('code', 'subscription_limit_reached');

        $business = $this->tenantFor($owner, Plan::where('slug', 'business')->firstOrFail(), 'api-business');
        $owner->forceFill(['current_tenant_id' => $business->id])->save();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('tenant.id', $business->id);
    }

    public function test_free_invite_and_product_creation_are_rejected_by_http_backend_checks(): void
    {
        $owner = $this->user('http-limit-owner');
        $plan = Plan::where('slug', 'free')->firstOrFail();
        $tenant = $this->tenantFor($owner, $plan, 'http-limit-workspace');
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
        $invitee = $this->user('http-invitee');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Referer', url('/team'))
            ->post('/team/invite', ['email' => $invitee->email, 'role' => 'Staff'])
            ->assertRedirect('/team')->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $invitee->id]);

        for ($i = 1; $i <= 100; $i++) {
            Product::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id, 'name' => 'HTTP Product '.$i,
                'sku' => 'HTTP-'.$i, 'unit' => 'pcs', 'track_inventory' => false,
                'is_active' => true, 'purchase_price' => 0, 'selling_price' => 0, 'cost_price' => 0,
            ]);
        }

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Referer', url('/products/create'))
            ->post('/products', [
                'name' => 'HTTP Product 101', 'sku' => 'HTTP-101', 'unit' => 'pcs',
                'track_inventory' => 0, 'is_active' => 1, 'minimum_stock' => 0,
            ])->assertRedirect('/products/create')->assertSessionHasErrors();
        $this->assertDatabaseMissing('products', ['tenant_id' => $tenant->id, 'sku' => 'HTTP-101']);
    }

    public function test_customers_are_unlimited_and_advanced_features_are_server_checked(): void
    {
        $usage = app(UsageService::class);
        $owner = $this->user('feature-owner');
        $free = $this->tenantFor($owner, Plan::where('slug', 'free')->firstOrFail(), 'feature-free');
        $usage->enforce($free, 'max_customers', 10000);
        $this->assertNull($usage->limit($free, 'max_customers'));

        try {
            $usage->enforceFeature($free, 'advanced_reports');
            $this->fail('Free plan must not have access to advanced reports.');
        } catch (SubscriptionLimitException) {
            $this->assertTrue(true);
        }

        $proTenant = $this->tenantFor($owner, Plan::where('slug', 'pro')->firstOrFail(), 'feature-pro');
        $businessTenant = $this->tenantFor($owner, Plan::where('slug', 'business')->firstOrFail(), 'feature-business');
        $usage->enforceFeature($proTenant, 'advanced_reports');
        $usage->enforceFeature($businessTenant, 'api_access');
    }

    public function test_yearly_checkout_is_rejected_until_an_official_annual_price_exists(): void
    {
        $owner = $this->user('yearly-owner');
        $tenant = $this->tenantFor($owner, Plan::where('slug', 'free')->firstOrFail(), 'yearly-workspace');
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'yearly'])
            ->assertSessionHasErrors('cycle');

        $this->assertSame(0, Plan::where('slug', 'starter')->firstOrFail()->price_yearly);
    }

    public function test_every_plan_enforces_its_exact_user_boundary(): void
    {
        $usage = app(UsageService::class);

        foreach ([
            'free' => 1,
            'starter' => 5,
            'pro' => 15,
            'business' => 50,
        ] as $slug => $limit) {
            $owner = $this->user("seat-{$slug}-owner");
            $tenant = $this->tenantFor($owner, Plan::where('slug', $slug)->firstOrFail(), "seat-{$slug}-workspace");

            for ($i = 1; $i < $limit; $i++) {
                $member = $this->user("seat-{$slug}-{$i}");
                $tenant->users()->attach($member->id, [
                    'role' => 'Staff', 'status' => 'active', 'joined_at' => now(),
                ]);
            }

            $this->assertSame($limit, $usage->usage($tenant, 'max_users'));

            try {
                $usage->enforceSeat($tenant);
                $this->fail("{$slug} must block seat {$limit} + 1.");
            } catch (SubscriptionLimitException $e) {
                $this->assertSame('User', $e->resource);
                $this->assertSame($limit, $e->limit);
            }
        }
    }

    public function test_pro_allows_ten_workspaces_and_blocks_the_eleventh(): void
    {
        $owner = $this->user('pro-workspace-owner');
        $plan = Plan::where('slug', 'pro')->firstOrFail();
        for ($i = 1; $i <= 10; $i++) {
            $this->tenantFor($owner, $plan, 'pro-workspace-'.$i);
        }

        $this->expectException(SubscriptionLimitException::class);
        app(UsageService::class)->enforceWorkspaceCreation($owner);
    }

    private function user(string $key): User
    {
        return User::create([
            'name' => ucfirst($key), 'email' => $key.'@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
    }

    public function test_starter_can_invite_staff_but_not_assign_advanced_roles(): void
    {
        $owner = $this->user('starter-role-owner');
        $starter = $this->tenantFor($owner, Plan::where('slug', 'starter')->firstOrFail(), 'starter-role-workspace');
        $owner->forceFill(['current_tenant_id' => $starter->id])->save();
        $invitee = $this->user('starter-staff');

        $this->actingAs($owner)->withSession(['tenant_id' => $starter->id])
            ->withHeader('Referer', url('/team'))
            ->post('/team/invite', ['email' => $invitee->email, 'role' => 'Staff'])
            ->assertRedirect('/team')->assertSessionHas('success');

        $this->actingAs($owner)->withSession(['tenant_id' => $starter->id])
            ->withHeader('Referer', url('/team'))
            ->post('/team/invite', ['email' => $this->user('starter-admin')->email, 'role' => 'Admin'])
            ->assertRedirect('/team')
            ->assertSessionHas('upgrade_required')
            ->assertSessionMissing('success');
    }


    private function tenantFor(User $owner, Plan $plan, string $key): Tenant
    {
        $tenant = Tenant::create([
            'name' => ucfirst($key), 'slug' => $key.'-'.uniqid(), 'owner_id' => $owner->id,
        ]);
        $tenant->users()->attach($owner->id, ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]);
        Subscription::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'billing_cycle' => 'monthly', 'amount' => $plan->price_monthly,
            'currency' => $plan->currency, 'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);
        return $tenant;
    }
}
