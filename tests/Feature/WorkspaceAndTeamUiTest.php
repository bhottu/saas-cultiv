<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Workspace quota prompt and team member removal.
 *
 * Two rules matter here: the "Add Workspace" entry point never disappears at the
 * limit (the user must still be able to find the upgrade), and the backend — not
 * the UI — is what refuses an over-quota workspace. On the team page the owner can
 * remove members, but never in a way that leaves the workspace without an Owner.
 */
class WorkspaceAndTeamUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => ucfirst(str_replace('-', ' ', $email)),
            'email' => $email.'@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }

    private function workspaceFor(User $owner, string $planSlug, string $slug): Tenant
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'owner_id' => $owner->id,
        ]);

        $tenant->users()->attach($owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::where('slug', $planSlug)->firstOrFail()->id,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return $tenant;
    }

    private function addMember(Tenant $tenant, User $user, string $role): TenantUser
    {
        $tenant->users()->attach($user->id, [
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);

        return TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail();
    }

    // ------------------------------------------------------------------ workspace quota

    public function test_add_workspace_button_stays_visible_when_the_limit_is_reached(): void
    {
        // Free allows exactly one workspace, and this owner already has it.
        $owner = $this->user('limit-owner');
        $this->workspaceFor($owner, 'free', 'limit-workspace');

        $html = $this->actingAs($owner)->get('/tenants')->assertOk()->getContent();

        $this->assertStringContainsString('Add Workspace', $html, 'The button must never be hidden at the limit.');
        $this->assertStringContainsString('Workspace limit reached', $html);
        $this->assertStringContainsString('Upgrade your plan to create additional workspaces.', $html);
        $this->assertStringContainsString('View Plans', $html);
    }

    public function test_add_workspace_offers_the_create_form_when_quota_remains(): void
    {
        // Starter allows three workspaces; this owner has one.
        $owner = $this->user('quota-owner');
        $this->workspaceFor($owner, 'starter', 'quota-workspace');

        $html = $this->actingAs($owner)->get('/tenants')->assertOk()->getContent();

        $this->assertStringContainsString('Add Workspace', $html);
        $this->assertStringContainsString('create-workspace', $html);
        $this->assertStringContainsString(route('tenants.store'), $html);
    }

    public function test_posting_past_the_workspace_limit_is_refused_by_the_backend(): void
    {
        $owner = $this->user('post-limit-owner');
        $this->workspaceFor($owner, 'free', 'post-limit-workspace');

        $this->actingAs($owner)
            ->withHeader('Referer', url('/tenants'))
            ->post('/tenants', ['name' => 'Second Workspace'])
            ->assertRedirect('/tenants')
            ->assertSessionHas('upgrade_required');

        $this->assertSame(1, $owner->ownedTenants()->count(), 'A workspace must not be created past the limit.');
    }

    public function test_posting_within_the_workspace_limit_creates_it(): void
    {
        $owner = $this->user('quota-post-owner');
        $this->workspaceFor($owner, 'starter', 'quota-post-workspace');

        $this->actingAs($owner)
            ->post('/tenants', ['name' => 'Second Workspace'])
            ->assertRedirect('/dashboard');

        $this->assertSame(2, $owner->ownedTenants()->count());
    }

    // ------------------------------------------------------------------ team removal

    public function test_owner_gets_a_confirmation_before_a_member_is_removed(): void
    {
        $owner = $this->user('team-owner');
        $staff = $this->user('team-staff');
        $tenant = $this->workspaceFor($owner, 'free', 'team-workspace');
        $membership = $this->addMember($tenant, $staff, 'Staff');

        $html = $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get('/team')->assertOk()->getContent();

        $this->assertStringContainsString('Remove', $html);
        $this->assertStringContainsString('Remove this team member?', $html);
        $this->assertStringContainsString('This user will no longer have access to this workspace.', $html);
        $this->assertStringContainsString(route('team.remove', $membership), $html);
    }

    public function test_a_member_without_manage_users_sees_no_remove_control(): void
    {
        $owner = $this->user('no-remove-owner');
        $staff = $this->user('no-remove-staff');
        $tenant = $this->workspaceFor($owner, 'free', 'no-remove-workspace');
        $this->addMember($tenant, $staff, 'Staff');

        $html = $this->actingAs($staff)->withSession(['tenant_id' => $tenant->id])
            ->get('/team')->assertOk()->getContent();

        $this->assertStringNotContainsString('Remove this team member?', $html);
    }

    public function test_removing_a_member_updates_the_list(): void
    {
        $owner = $this->user('remove-owner');
        $staff = $this->user('remove-staff');
        $tenant = $this->workspaceFor($owner, 'free', 'remove-workspace');
        $membership = $this->addMember($tenant, $staff, 'Staff');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->delete('/team/'.$membership->id)
            ->assertRedirect()
            ->assertSessionHas('success');

        // Membership only — the row is KEPT as history (soft delete + status 'removed')
        // and the user account is never touched. What changes is access: the member is
        // gone from the list and the workspace no longer counts them as a member.
        $membership->refresh();
        $this->assertSame(TenantUser::STATUS_REMOVED, $membership->status);
        $this->assertNotNull($membership->deleted_at);
        $this->assertDatabaseHas('tenant_user', ['id' => $membership->id]);
        $this->assertDatabaseHas('users', ['id' => $staff->id]);
        $this->assertNull($staff->fresh()->membershipIn($tenant));
        $this->assertSame(1, $tenant->seatCount(), 'Only the Owner keeps a seat.');

        $html = $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get('/team')->assertOk()->getContent();

        // Gone from the active list (no remove form) but kept as visible history with a
        // restore action, which is the whole point of the soft delete. The action is
        // matched with its quotes: /team/{id} is a prefix of /team/{id}/restore.
        $this->assertStringNotContainsString('action="'.route('team.remove', $membership).'"', $html);
        $this->assertStringContainsString('action="'.route('team.restore', $membership).'"', $html);
        $this->assertStringContainsString('Removed members', $html);
    }

    public function test_the_owner_membership_cannot_be_removed(): void
    {
        $owner = $this->user('keep-owner');
        $tenant = $this->workspaceFor($owner, 'free', 'keep-workspace');
        $ownerMembership = TenantUser::where('tenant_id', $tenant->id)
            ->where('user_id', $owner->id)->firstOrFail();

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->delete('/team/'.$ownerMembership->id)
            ->assertForbidden();

        $this->assertDatabaseHas('tenant_user', ['id' => $ownerMembership->id]);
    }
}
