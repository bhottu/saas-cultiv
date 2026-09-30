<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tenant audit log explorer: Pro/Business entitlement, governance permission,
 * tenant isolation and metadata redaction.
 */
class AuditLogExplorerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->owner = User::create([
            'name' => 'Audit Owner', 'email' => 'audit-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Audit Workspace', 'slug' => 'audit-workspace', 'owner_id' => $this->owner->id,
        ]);

        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
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

    private function asOwner(): self
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    private function log(array $overrides = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'action' => 'product.created',
            'resource_type' => 'Product',
            'resource_id' => 1,
            'ip_address' => '127.0.0.1',
            'metadata' => [],
        ], $overrides));
    }

    public function test_free_plan_is_refused(): void
    {
        $this->log();

        // Entitlement gate runs before the controller, so no audit data is queried.
        $this->asOwner()->get('/audit-logs')->assertForbidden();
    }

    public function test_pro_plan_can_read_the_audit_log(): void
    {
        $this->onPlan('pro');
        $this->log(['action' => 'product.created', 'resource_type' => 'Product']);

        $this->asOwner()->get('/audit-logs')
            ->assertOk()
            ->assertSee('Audit Log')
            ->assertSee('product.created')
            ->assertSee('Total entries');
    }

    public function test_manager_without_governance_permission_is_refused(): void
    {
        $this->onPlan('business');

        $manager = User::create([
            'name' => 'Audit Manager', 'email' => 'audit-manager@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $this->tenant->users()->attach($manager->id, [
            'role' => 'Manager', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->log();

        // Manager can read records, but audit.view maps to manage_settings.
        $this->actingAs($manager)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/audit-logs')
            ->assertForbidden();
    }

    public function test_audit_log_never_exposes_another_workspaces_entries(): void
    {
        $this->onPlan('business');

        $stranger = User::create([
            'name' => 'Other Owner', 'email' => 'other-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $other = Tenant::create([
            'name' => 'Other Workspace', 'slug' => 'other-workspace', 'owner_id' => $stranger->id,
        ]);
        $other->users()->attach($stranger->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->log(['action' => 'product.created']);
        $foreign = AuditLog::create([
            'tenant_id' => $other->id, 'user_id' => $stranger->id,
            'action' => 'secret.other.workspace.action', 'resource_type' => 'Secret',
            'resource_id' => 999, 'ip_address' => '10.0.0.9', 'metadata' => [],
        ]);

        $this->asOwner()->get('/audit-logs')
            ->assertOk()
            ->assertSee('product.created')
            ->assertDontSee('secret.other.workspace.action');

        // Direct id access from the wrong workspace is a 404, never a 200.
        $this->asOwner()->get("/audit-logs/{$foreign->id}")->assertNotFound();
    }

    public function test_filters_and_search_narrow_the_listing(): void
    {
        $this->onPlan('business');
        $this->log(['action' => 'sale.created', 'resource_type' => 'Sale', 'resource_id' => 10]);
        $this->log(['action' => 'sale.refunded', 'resource_type' => 'Sale', 'resource_id' => 11]);
        $this->log(['action' => 'product.created', 'resource_type' => 'Product', 'resource_id' => 12]);

        // Area filter matches sale.* but never product.*. Assertions target the table
        // row marker, because the filter dropdown legitimately lists every action.
        $this->asOwner()->get('/audit-logs?action_group=sale')
            ->assertOk()
            ->assertSee('data-audit-action="sale.created"', false)
            ->assertSee('data-audit-action="sale.refunded"', false)
            ->assertDontSee('data-audit-action="product.created"', false);

        // Exact action
        $this->asOwner()->get('/audit-logs?action=sale.refunded')
            ->assertOk()
            ->assertSee('data-audit-action="sale.refunded"', false)
            ->assertDontSee('data-audit-action="sale.created"', false);

        // Free-text search over action / resource type / id
        $this->asOwner()->get('/audit-logs?search=Product')
            ->assertOk()
            ->assertSee('data-audit-action="product.created"', false)
            ->assertDontSee('data-audit-action="sale.created"', false);
    }

    public function test_date_filters_are_applied(): void
    {
        $this->onPlan('business');
        $this->log(['action' => 'old.entry', 'created_at' => now()->subDays(40)]);
        $this->log(['action' => 'recent.entry', 'created_at' => now()]);

        $this->asOwner()
            ->get('/audit-logs?from='.now()->subDays(7)->toDateString())
            ->assertOk()
            ->assertSee('data-audit-action="recent.entry"', false)
            ->assertDontSee('data-audit-action="old.entry"', false);
    }

    public function test_detail_page_redacts_sensitive_metadata(): void
    {
        $this->onPlan('business');

        $entry = $this->log([
            'action' => 'auth.login',
            'resource_type' => null,
            'resource_id' => null,
            'metadata' => [
                'name' => 'Budi',
                'password' => 'super-secret',
                'api_key' => 'ak_live_123',
                'nested' => ['token' => 'tok_abc', 'role' => 'Owner'],
            ],
        ]);

        $this->asOwner()->get("/audit-logs/{$entry->id}")
            ->assertOk()
            ->assertSee('Budi')
            ->assertSee('[redacted]')
            ->assertDontSee('super-secret')
            ->assertDontSee('ak_live_123')
            ->assertDontSee('tok_abc');
    }

    public function test_listing_keeps_deletion_tone_and_system_actor(): void
    {
        $this->onPlan('business');
        $this->log(['action' => 'product.deleted', 'user_id' => null]);

        $this->asOwner()->get('/audit-logs')
            ->assertOk()
            ->assertSee('product.deleted')
            ->assertSee('System');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/audit-logs')->assertRedirect('/login');
    }
}