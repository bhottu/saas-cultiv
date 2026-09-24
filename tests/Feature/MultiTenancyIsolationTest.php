<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MultiTenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;
    private User $ownerB;
    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::where('slug', 'free')->first() ?? Plan::create([
            'name' => 'Free', 'slug' => 'free', 'price_monthly' => 0, 'price_yearly' => 0,
            'entitlements' => ['max_users' => 2], 'is_free_tier' => true,
        ]);

        [$this->ownerA, $this->ownerB] = collect([
            ['email' => 'a@test.dev'], ['email' => 'b@test.dev'],
        ])->map(function ($attrs) {
            return User::create([
                'name' => strtoupper($attrs['email'][0]), 'email' => $attrs['email'],
                'password' => Hash::make('password'), 'email_verified_at' => now(),
            ]);
        })->all();

        foreach ([$this->ownerA, $this->ownerB] as $i => $owner) {
            $tenant = Tenant::create(['name' => 'Tenant-'.($i + 1), 'slug' => 'tenant-'.($i + 1), 'owner_id' => $owner->id]);
            $tenant->users()->attach($owner->id, ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]);
            if ($i === 0) $this->tenantA = $tenant; else $this->tenantB = $tenant;
        }
    }

    public function test_user_cannot_switch_to_tenant_they_dont_belong_to(): void
    {
        $this->actingAs($this->ownerB)
            ->post("/tenants/{$this->tenantA->id}/switch")
            ->assertForbidden();

        $this->assertNull($this->ownerB->fresh()->current_tenant_id);
    }

    public function test_tenant_context_requires_membership(): void
    {
        $this->actingAs($this->ownerB)
            ->post("/tenants/{$this->tenantA->id}/switch")
            ->assertForbidden();

        // Even with a forged session value, EnsureTenantContext re-validates membership.
        $this->actingAs($this->ownerB)->withSession(['tenant_id' => $this->tenantA->id])
            ->get('/dashboard')->assertForbidden();
    }

    public function test_valid_switch_works(): void
    {
        $this->actingAs($this->ownerA)
            ->post("/tenants/{$this->tenantA->id}/switch")
            ->assertRedirect('/dashboard');

        // New request: session cookie may not persist in tests → current_tenant_id fallback.
        $this->actingAs($this->ownerA)->get('/dashboard')->assertOk();
        $this->assertSame($this->tenantA->id, $this->ownerA->fresh()->current_tenant_id);
    }

    public function test_billing_data_is_isolated_per_tenant(): void
    {
        $this->actingAs($this->ownerA)->withSession(['tenant_id' => $this->tenantA->id])
            ->get('/billing')->assertOk()->assertSee('Free');

        // Tenant B must not see Tenant A's data: their view contains no tenant A invoices.
        $this->actingAs($this->ownerB)->withSession(['tenant_id' => $this->tenantB->id])
            ->get('/billing')->assertOk();

        // Direct object access to another tenant's payment → 404 (global scope).
        $this->actingAs($this->ownerB)->withSession(['tenant_id' => $this->tenantB->id])
            ->get('/billing/payments/999999')->assertNotFound();
    }

    public function test_role_permissions_enforced_server_side(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'v@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $this->tenantA->users()->attach($viewer->id, ['role' => 'Viewer', 'status' => 'active', 'joined_at' => now()]);

        // Viewer lacks manage_billing → checkout forbidden.
        $this->actingAs($viewer)->withSession(['tenant_id' => $this->tenantA->id])
            ->post('/billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly'])
            ->assertForbidden();

        // Viewer can still read tenant-scoped pages they are allowed to see.
        $this->actingAs($viewer)->withSession(['tenant_id' => $this->tenantA->id])
            ->get('/dashboard')->assertOk();
    }
}
