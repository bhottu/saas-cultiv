<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Platform Admin panel.
 *
 * Two things matter most here: a normal user must never reach /admin, and an
 * admin must be able to see across workspaces while the tenant app keeps its
 * isolation untouched.
 */
class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $member;
    private Tenant $workspaceA;
    private Tenant $workspaceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->admin = User::create([
            'name' => 'Platform Admin', 'email' => 'admin@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
            'is_platform_admin' => true,
        ]);

        $this->member = User::create([
            'name' => 'Normal User', 'email' => 'member@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->workspaceA = $this->makeWorkspace('Toko ABC', $this->member);
        $this->workspaceB = $this->makeWorkspace('Toko XYZ', $this->member);

        $this->seedBusinessData();
    }

    private function makeWorkspace(string $name, User $owner): Tenant
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

    private function seedBusinessData(): void
    {
        foreach ([$this->workspaceA, $this->workspaceB] as $tenant) {
            Product::create([
                'tenant_id' => $tenant->id, 'sku' => 'SKU-'.$tenant->id, 'name' => 'Monstera '.$tenant->name,
                'unit' => 'pcs', 'selling_price' => 1000, 'track_inventory' => true, 'is_active' => true,
            ]);
            // Same customer name in two workspaces: they must never be merged.
            Customer::create(['tenant_id' => $tenant->id, 'name' => 'Budi', 'phone' => '0812', 'is_active' => true]);
            Sale::create([
                'tenant_id' => $tenant->id, 'invoice_number' => 'INV-'.$tenant->id, 'status' => 'completed',
                'payment_status' => 'paid', 'subtotal' => 1000, 'discount' => 0, 'tax' => 0,
                'shipping' => 0, 'total' => 1000, 'total_cogs' => 500, 'sold_at' => now(),
            ]);
        }

        $plan = Plan::where('slug', 'business')->firstOrFail();
        Subscription::create([
            'tenant_id' => $this->workspaceA->id, 'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin)->withSession(['tenant_id' => $this->workspaceA->id]);
    }

    // ------------------------------------------------------------------ security

    public function test_a_normal_user_cannot_reach_any_admin_route(): void
    {
        $urls = [
            '/admin', '/admin/users', '/admin/workspaces', '/admin/products', '/admin/customers',
            '/admin/sales', '/admin/purchases', '/admin/inventory', '/admin/subscriptions',
            '/admin/payments', '/admin/plans', '/admin/audit-logs',
        ];

        foreach ($urls as $url) {
            // Server-side gate, not a hidden menu.
            $this->actingAs($this->member)->get($url)->assertForbidden();
        }
    }

    public function test_guests_are_redirected_from_admin(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/admin/users')->assertRedirect('/login');
    }

    public function test_every_admin_page_renders_for_a_platform_admin(): void
    {
        $urls = [
            '/admin', '/admin/users', '/admin/users/'.$this->member->id,
            '/admin/workspaces', '/admin/workspaces/'.$this->workspaceA->id,
            '/admin/products', '/admin/customers', '/admin/sales', '/admin/purchases',
            '/admin/inventory', '/admin/subscriptions', '/admin/payments', '/admin/plans', '/admin/audit-logs',
        ];

        foreach ($urls as $url) {
            $response = $this->asAdmin()->get($url);

            // Include the URL in the failure so the broken page is obvious.
            $this->assertSame(200, $response->status(), "{$url} returned HTTP ".$response->status());
        }
    }

    // ------------------------------------------------------------------ cross-workspace

    public function test_admin_sees_data_from_every_workspace(): void
    {
        $this->asAdmin()->get('/admin/products')->assertOk()
            ->assertSee('Monstera Toko ABC')
            ->assertSee('Monstera Toko XYZ');

        $this->asAdmin()->get('/admin/workspaces')->assertOk()
            ->assertSee('Toko ABC')
            ->assertSee('Toko XYZ');
    }

    public function test_customers_keep_their_workspace_context_and_are_not_merged(): void
    {
        $html = $this->asAdmin()->get('/admin/customers')->assertOk()->getContent();

        // Two rows for "Budi", one per workspace — never one deduplicated record.
        $this->assertSame(2, substr_count($html, '>Budi<'));
        $this->assertStringContainsString('Toko ABC', $html);
        $this->assertStringContainsString('Toko XYZ', $html);
    }

    public function test_workspace_filter_narrows_the_explorer(): void
    {
        $this->asAdmin()->get('/admin/products?tenant_id='.$this->workspaceA->id)
            ->assertOk()
            ->assertSee('Monstera Toko ABC')
            ->assertDontSee('Monstera Toko XYZ');
    }

    public function test_tenant_app_isolation_is_untouched_by_the_admin_panel(): void
    {
        $this->actingAs($this->member)
            ->withSession(['tenant_id' => $this->workspaceA->id])
            ->get('/products')
            ->assertOk()
            ->assertSee('Monstera Toko ABC')
            ->assertDontSee('Monstera Toko XYZ');
    }

    // ------------------------------------------------------------------ soft delete

    public function test_admin_sees_soft_deleted_workspaces_and_can_restore_them(): void
    {
        $this->actingAs($this->member)->delete("/tenants/{$this->workspaceB->id}");

        $this->asAdmin()->get('/admin/workspaces?deleted=1')
            ->assertOk()
            ->assertSee('Toko XYZ')
            ->assertSee(__('Soft deleted'));

        $this->asAdmin()->get("/admin/workspaces/{$this->workspaceB->id}")->assertOk()->assertSee('Toko XYZ');

        $this->asAdmin()->post("/admin/workspaces/{$this->workspaceB->id}/restore")->assertRedirect();

        $this->assertNotSoftDeleted('tenants', ['id' => $this->workspaceB->id]);
    }

    public function test_restoring_a_workspace_is_audited(): void
    {
        $this->actingAs($this->member)->delete("/tenants/{$this->workspaceB->id}");

        $this->asAdmin()->post("/admin/workspaces/{$this->workspaceB->id}/restore");

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'admin.restored_workspace',
        ]);
    }

    public function test_admin_cannot_hard_delete_a_workspace(): void
    {
        $this->actingAs($this->member)->delete("/tenants/{$this->workspaceB->id}");

        // The panel offers restore only; the row must still be there afterwards.
        $this->asAdmin()->post("/admin/workspaces/{$this->workspaceB->id}/restore");

        $this->assertDatabaseHas('tenants', ['id' => $this->workspaceB->id]);
    }

    // ------------------------------------------------------------------ secret safety

    public function test_audit_log_metadata_is_redacted_in_the_admin_panel(): void
    {
        AuditLog::create([
            'tenant_id' => $this->workspaceA->id,
            'user_id' => $this->member->id,
            'action' => 'test.event',
            'resource_type' => 'Product',
            'resource_id' => 1,
            'ip_address' => '10.0.0.1',
            'metadata' => ['visible' => 'keep-me', 'password' => 'super-secret', 'api_key' => 'ak_live_1'],
        ]);

        $html = $this->asAdmin()->get('/admin/audit-logs')->assertOk()->getContent();

        $this->assertStringContainsString('keep-me', $html);
        $this->assertStringNotContainsString('super-secret', $html);
        $this->assertStringNotContainsString('ak_live_1', $html);
    }

    public function test_admin_users_page_never_renders_a_password_hash(): void
    {
        $html = $this->asAdmin()->get('/admin/users')->assertOk()->getContent();

        $this->assertStringContainsString('member@test.dev', $html);
        $this->assertStringNotContainsString('$2y$', $html, 'A bcrypt hash must never reach the admin page.');
    }

    public function test_navigation_is_present_on_admin_pages(): void
    {
        $html = $this->asAdmin()->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('id="sidebar"', $html);
        $this->assertStringContainsString(route('admin.users.index'), $html);
        $this->assertStringContainsString(route('admin.audit-logs.index'), $html);
    }

    public function test_admin_navigation_is_hidden_from_normal_users(): void
    {
        $this->asAdmin()->get('/admin')->assertOk()->assertSee(route('admin.dashboard'), false);

        $this->actingAs($this->member)
            ->withSession(['tenant_id' => $this->workspaceA->id])
            ->get('/dashboard')->assertOk()
            ->assertDontSee(route('admin.dashboard'), false);
    }
}
