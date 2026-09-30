<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Business demo account is what we hand out to people trying the paid tiers, so it
 * has to actually land on the Business plan and be able to log in, not just have rows.
 */
class BusinessSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_verified_owner_on_the_business_plan(): void
    {
        $this->seed(BusinessSeeder::class);

        $user = User::where('email', 'bisnis@cultiv.id')->firstOrFail();

        // The credentials the demo account is advertised with must really work.
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertNotNull($user->email_verified_at, 'A verified account skips the email check on login.');

        $tenant = Tenant::where('slug', 'bisnis-workspace')->firstOrFail();
        $this->assertSame($user->id, $tenant->owner_id);
        $this->assertSame($tenant->id, $user->fresh()->current_tenant_id);

        $membership = TenantUser::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Owner', $membership->role);
        $this->assertSame('active', $membership->status);
    }

    public function test_the_workspace_lands_on_an_active_business_subscription(): void
    {
        $this->seed(BusinessSeeder::class);

        $tenant = Tenant::where('slug', 'bisnis-workspace')->firstOrFail();
        $plan = Plan::where('slug', 'business')->firstOrFail();

        $subscription = $tenant->activeSubscription()->firstOrFail();
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame('monthly', $subscription->billing_cycle);
        $this->assertSame($plan->price_monthly, $subscription->amount);
        $this->assertTrue($subscription->current_period_end->isFuture());
    }

    public function test_the_seeded_account_can_reach_the_app_and_keeps_business_limits(): void
    {
        $this->seed(BusinessSeeder::class);

        $user = User::where('email', 'bisnis@cultiv.id')->firstOrFail();

        $this->post('/login', ['email' => 'bisnis@cultiv.id', 'password' => 'password'])
            ->assertRedirect();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        // Business is the unlimited-workspaces tier, so the plan entitlement must come
        // through as null and not as the free tier's 1.
        $plan = Plan::where('slug', 'business')->firstOrFail();
        $this->assertNull($plan->entitlements['max_workspaces']);
        $this->assertTrue($plan->entitlements['api_access']);
    }

    public function test_running_it_twice_does_not_stack_a_second_subscription(): void
    {
        $this->seed(BusinessSeeder::class);
        $this->seed(BusinessSeeder::class);

        $tenant = Tenant::where('slug', 'bisnis-workspace')->firstOrFail();

        $this->assertSame(1, $tenant->activeSubscription()->count());
        $this->assertSame(1, TenantUser::where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, User::where('email', 'bisnis@cultiv.id')->count());
    }
}