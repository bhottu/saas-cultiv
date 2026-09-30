<?php

namespace Tests\Feature;

use App\Http\Controllers\TenantController;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Removing a team member ends the MEMBERSHIP, never the account.
 *
 * The scenario this file pins down:
 *
 *   owner invites staff@… → staff accepts → owner removes them → staff signs in again
 *
 * Expected SaaS behaviour: the account still logs in, no "403 Access denied" page is
 * ever shown, the workspace hub says the previous workspace is out of reach, and that
 * old workspace stays closed to them (tenant isolation is untouched). The users row
 * must still exist — only the tenant_user link is revoked.
 */
class WorkspaceMembershipRemovalTest extends TestCase
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

    /** Workspace on Starter (5 seats) unless a specific plan is requested. */
    private function workspaceFor(User $owner, string $slug, string $planSlug = 'starter'): Tenant
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'owner_id' => $owner->id,
        ]);

        $tenant->users()->attach($owner->id, [
            'role' => 'Owner', 'status' => TenantUser::STATUS_ACTIVE, 'joined_at' => now(),
        ]);

        $plan = Plan::where('slug', $planSlug)->firstOrFail();

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'amount' => $plan->price_monthly,
            'currency' => $plan->currency,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return $tenant;
    }

    private function membershipOf(Tenant $tenant, User $user): TenantUser
    {
        return TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail();
    }

    /** Full round trip: invite from /team, then accept as the invitee. */
    private function inviteAndAccept(Tenant $tenant, User $owner, User $invitee, string $role = 'Staff'): TenantUser
    {
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->post('/team/invite', ['email' => $invitee->email, 'role' => $role])
            ->assertRedirect()
            ->assertSessionHas('success');

        $membership = $this->membershipOf($tenant, $invitee);

        $this->actingAs($invitee)
            ->post(route('team.invitations.accept', $membership))
            ->assertRedirect()
            ->assertSessionHas('success');

        return $membership->refresh();
    }

    /** Owner removes the member through the real endpoint (DELETE /team/{membership}). */
    private function remove(Tenant $tenant, User $owner, TenantUser $membership): void
    {
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->delete(route('team.remove', $membership))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    // ------------------------------------------------------------- Test 1: invitation

    public function test_invited_staff_can_open_the_workspace_after_accepting(): void
    {
        $owner = $this->user('accept-owner');
        $staff = $this->user('accept-staff');
        $tenant = $this->workspaceFor($owner, 'accept-workspace');

        $membership = $this->inviteAndAccept($tenant, $owner, $staff);

        $this->assertSame(TenantUser::STATUS_ACTIVE, $membership->status);
        $this->assertNotNull($staff->fresh()->membershipIn($tenant));

        // The accepted invitation is a real membership: the workspace opens normally.
        $this->actingAs($staff->fresh())->withSession(['tenant_id' => $tenant->id])
            ->get('/dashboard')->assertOk()->assertSee($tenant->name);
    }

    // ------------------------------------------------------------------ Test 2: removal

    public function test_a_removed_member_loses_access_to_every_workspace_page(): void
    {
        $owner = $this->user('lost-owner');
        $staff = $this->user('lost-staff');
        $tenant = $this->workspaceFor($owner, 'lost-workspace');
        $membership = $this->inviteAndAccept($tenant, $owner, $staff);

        $this->remove($tenant, $owner, $membership);

        // Owner-side bookkeeping: the membership is revoked, the account is not.
        $this->assertSoftDeleted('tenant_user', ['id' => $membership->id]);
        $this->assertSame(TenantUser::STATUS_REMOVED, $membership->fresh()->status);
        $this->assertNull($staff->fresh()->membershipIn($tenant));
        $this->assertNull($staff->fresh()->current_tenant_id, 'The stale workspace pointer is cleared.');

        // Every tenant-scoped page now bounces to the hub instead of rendering data.
        foreach (['/dashboard', '/products', '/customers', '/sales', '/billing', '/team'] as $url) {
            $this->actingAs($staff->fresh())->withSession(['tenant_id' => $tenant->id])
                ->get($url)
                ->assertRedirect(route('tenants.index'));
        }

        // No bookmark, no switcher entry: the workspace is gone from their own hub too.
        $html = $this->actingAs($staff->fresh())->get(route('tenants.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('tenants.switch', $tenant), $html);
    }

    // -------------------------------------------------------- Test 3: signing in again

    public function test_a_removed_member_signs_in_again_and_lands_on_the_workspace_hub(): void
    {
        $owner = $this->user('signin-owner');
        $staff = $this->user('signin-staff');
        $tenant = $this->workspaceFor($owner, 'signin-workspace');
        $membership = $this->inviteAndAccept($tenant, $owner, $staff);

        $this->remove($tenant, $owner, $membership);

        // Clean browser: the returning account logs in with the same credentials.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->post('/login', ['email' => $staff->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        // /dashboard needs a workspace → the hub. NOT the 403 page.
        $this->get('/dashboard')->assertRedirect(route('tenants.index'));

        $html = $this->get(route('tenants.index'))->assertOk()->getContent();

        $this->assertStringContainsString('You no longer have access to your previous workspace.', $html);
        $this->assertStringContainsString($tenant->name, $html, 'The revoked workspace is named.');
        $this->assertStringNotContainsString('Access denied', $html);

        // Empty state, not an error: create one, or wait for an invitation. (The rendered
        // apostrophe is HTML-escaped, so the copy is asserted without it.)
        $this->assertStringContainsString('No Workspace Available', $html);
        $this->assertStringContainsString('have access to any workspace', $html);
        $this->assertStringContainsString('Create Workspace', $html);
        $this->assertStringContainsString('Waiting for invitation', $html);
    }

    // --------------------------------------------------------- Test 4: account + history

    public function test_removing_a_member_keeps_the_user_account_and_the_history(): void
    {
        $owner = $this->user('keepacc-owner');
        $staff = $this->user('keepacc-staff');
        $tenant = $this->workspaceFor($owner, 'keepacc-workspace');
        $membership = $this->inviteAndAccept($tenant, $owner, $staff);

        $this->remove($tenant, $owner, $membership);

        // The account is untouched — same credentials, same verification.
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'email' => $staff->email]);
        $this->assertTrue(Hash::check('password', $staff->fresh()->password));
        $this->assertNotNull($staff->fresh()->email_verified_at);

        // The membership row survives as history instead of vanishing.
        $this->assertDatabaseHas('tenant_user', [
            'id' => $membership->id,
            'tenant_id' => $tenant->id,
            'user_id' => $staff->id,
            'status' => TenantUser::STATUS_REMOVED,
        ]);
        $this->assertNotNull(DB::table('tenant_user')->where('id', $membership->id)->value('deleted_at'));

        // And the change is traceable in the existing audit trail.
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.removed']);
    }

    // --------------------------------------------------------------- authorization

    public function test_a_staff_member_cannot_remove_anybody(): void
    {
        $owner = $this->user('perm-owner');
        $staff = $this->user('perm-staff');
        $tenant = $this->workspaceFor($owner, 'perm-workspace');
        $this->inviteAndAccept($tenant, $owner, $staff);

        $ownerMembership = $this->membershipOf($tenant, $owner);

        // Staff has no manage_users: the endpoint refuses before anything is touched.
        $this->actingAs($staff->fresh())->withSession(['tenant_id' => $tenant->id])
            ->delete(route('team.remove', $ownerMembership))
            ->assertForbidden();

        $ownerMembership->refresh();
        $this->assertFalse($ownerMembership->trashed());
        $this->assertSame(TenantUser::STATUS_ACTIVE, $ownerMembership->status);
    }

    public function test_a_removed_member_cannot_act_on_the_old_workspace(): void
    {
        $owner = $this->user('gone-owner');
        $staff = $this->user('gone-staff');
        $tenant = $this->workspaceFor($owner, 'gone-workspace');
        $membership = $this->inviteAndAccept($tenant, $owner, $staff);

        $this->remove($tenant, $owner, $membership);

        $ownerMembership = $this->membershipOf($tenant, $owner);

        // Their session may still carry the workspace id, but the membership is gone: the
        // request never even reaches the team controller.
        $this->actingAs($staff->fresh())->withSession(['tenant_id' => $tenant->id])
            ->delete(route('team.remove', $ownerMembership))
            ->assertRedirect(route('tenants.index'));

        $this->assertFalse($ownerMembership->fresh()->trashed());
    }

    // -------------------------------------------------------- restore / re-invite

    public function test_the_owner_can_restore_a_removed_membership(): void
    {
        $owner = $this->user('restore-owner');
        $staff = $this->user('restore-staff');
        $tenant = $this->workspaceFor($owner, 'restore-workspace');
        $membership = $this->inviteAndAccept($tenant, $owner, $staff);

        $this->remove($tenant, $owner, $membership);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->patch(route('team.restore', $membership))
            ->assertRedirect()
            ->assertSessionHas('success');

        $membership->refresh();
        $this->assertSame(TenantUser::STATUS_ACTIVE, $membership->status);
        $this->assertNull($membership->deleted_at);
        $this->assertNotNull($membership->joined_at);
        $this->assertNotNull($staff->fresh()->membershipIn($tenant));

        // Access really is back — and it is the SAME membership id that was revived.
        $this->assertSame($membership->id, $this->membershipOf($tenant, $staff)->id);
        $this->actingAs($staff->fresh())->withSession(['tenant_id' => $tenant->id])
            ->get('/dashboard')->assertOk();
    }

    public function test_restoring_cannot_exceed_the_plan_seat_limit(): void
    {
        $owner = $this->user('seat-owner');
        $staff = $this->user('seat-staff');
        $tenant = $this->workspaceFor($owner, 'seat-workspace', 'free'); // 1 seat: the Owner's

        $tenant->users()->attach($staff->id, [
            'role' => 'Staff', 'status' => TenantUser::STATUS_ACTIVE, 'joined_at' => now(),
        ]);
        $membership = $this->membershipOf($tenant, $staff);

        $this->remove($tenant, $owner, $membership);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->withHeader('Referer', url('/team'))
            ->patch(route('team.restore', $membership))
            ->assertRedirect('/team')
            ->assertSessionHasErrors();

        // A refused restore leaves the membership revoked: the plan is never exceeded.
        $this->assertTrue($membership->fresh()->trashed());
        $this->assertSame(1, $tenant->seatCount());
    }

    public function test_a_removed_member_can_be_invited_again_on_the_same_row(): void
    {
        $owner = $this->user('reinvite-owner');
        $staff = $this->user('reinvite-staff');
        $tenant = $this->workspaceFor($owner, 'reinvite-workspace');
        $membership = $this->inviteAndAccept($tenant, $owner, $staff);

        $this->remove($tenant, $owner, $membership);

        // Normal invite flow: the revoked row is revived, never duplicated — the
        // (tenant_id, user_id) pair is unique and stays reserved for this account.
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->post('/team/invite', ['email' => $staff->email, 'role' => 'Staff'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, TenantUser::withTrashed()
            ->where('tenant_id', $tenant->id)->where('user_id', $staff->id)->count());

        $resent = $this->membershipOf($tenant, $staff);
        $this->assertSame($membership->id, $resent->id);
        $this->assertSame(TenantUser::STATUS_INVITED, $resent->status);
        $this->assertNull($resent->deleted_at);

        $this->actingAs($staff)->post(route('team.invitations.accept', $resent))->assertRedirect();
        $this->actingAs($staff->fresh())->withSession(['tenant_id' => $tenant->id])
            ->get('/dashboard')->assertOk();
    }

    // ------------------------------------------------------------------ hub rendering

    public function test_the_removed_history_is_visible_only_to_team_managers(): void
    {
        $owner = $this->user('hist-owner');
        $staff = $this->user('hist-staff');
        $removed = $this->user('hist-removed');
        $tenant = $this->workspaceFor($owner, 'hist-workspace');

        $this->inviteAndAccept($tenant, $owner, $staff);
        $removedMembership = $this->inviteAndAccept($tenant, $owner, $removed);

        $this->remove($tenant, $owner, $removedMembership);

        $ownerHtml = $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get('/team')->assertOk()->getContent();
        $this->assertStringContainsString('Removed members', $ownerHtml);
        $this->assertStringContainsString(route('team.restore', $removedMembership), $ownerHtml);

        $staffHtml = $this->actingAs($staff)->withSession(['tenant_id' => $tenant->id])
            ->get('/team')->assertOk()->getContent();
        $this->assertStringNotContainsString('Removed members', $staffHtml);
        $this->assertStringNotContainsString(route('team.restore', $removedMembership), $staffHtml);
    }

    public function test_a_brand_new_account_gets_the_empty_state_without_the_removal_notice(): void
    {
        // The notice is derived from real history, so an account that never lost a
        // workspace must not be told that it did.
        $fresh = $this->user('fresh-account');

        $html = $this->actingAs($fresh)->get(route('tenants.index'))->assertOk()->getContent();

        $this->assertStringContainsString('No Workspace Available', $html);
        $this->assertStringContainsString('Create Workspace', $html);
        $this->assertStringNotContainsString('You no longer have access to your previous workspace.', $html);
    }

    // ------------------------------------------------- Workspace visibility in the shell

    /**
     * The "Switch workspace" dropdown on its own.
     *
     * The shell deliberately keeps other workspace references — the notification bell
     * still says "X invited you to Y", which is history, not navigation — so a
     * visibility assertion has to look at the switcher itself instead of the whole page.
     */
    private function switcher(string $html): string
    {
        $afterLabel = str($html)->after('Switch workspace');

        $this->assertNotSame($html, (string) $afterLabel, 'The workspace switcher is not rendered.');

        return $afterLabel->before('Manage workspaces')->toString();
    }

    /**
     * A revoked membership is kept as history, but a belongsToMany bypasses the
     * TenantUser model, so its SoftDeletes scope never runs. Without an explicit filter
     * the shell happily offered "switch into" a workspace the account was removed from.
     */
    public function test_the_header_switcher_only_lists_workspaces_with_an_active_membership(): void
    {
        $ownerBisnis = $this->user('bisnis-owner');
        $ownerDemo = $this->user('demo-owner');
        $staff = $this->user('shell-staff');

        $bisnis = $this->workspaceFor($ownerBisnis, 'bisnis-cultiv');
        $demo = $this->workspaceFor($ownerDemo, 'demo-workspace');

        $this->inviteAndAccept($bisnis, $ownerBisnis, $staff);
        $membershipDemo = $this->inviteAndAccept($demo, $ownerDemo, $staff);

        // While both memberships are active, both workspaces are offered.
        $before = $this->actingAs($staff->fresh())->withSession(['tenant_id' => $bisnis->id])
            ->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString(route('tenants.switch', $bisnis), $before);
        $this->assertStringContainsString(route('tenants.switch', $demo), $before);

        $this->remove($demo, $ownerDemo, $membershipDemo);

        // The account keeps a live workspace, so the switcher still renders — but the
        // revoked one must be gone from it, together with its name and its switch action.
        $after = $this->actingAs($staff->fresh())->withSession(['tenant_id' => $bisnis->id])
            ->get('/dashboard')->assertOk()->getContent();

        $switcher = $this->switcher($after);

        $this->assertStringContainsString(route('tenants.switch', $bisnis), $switcher);
        $this->assertStringContainsString($bisnis->name, $switcher);
        $this->assertStringNotContainsString(route('tenants.switch', $demo), $switcher);
        $this->assertStringNotContainsString($demo->name, $switcher);

        // The same revocation the header hides is still refused server-side.
        $this->actingAs($staff->fresh())
            ->post(route('tenants.switch', $demo))->assertForbidden();
    }

    public function test_the_workspace_list_still_shows_every_active_membership(): void
    {
        $ownerBisnis = $this->user('keep-bisnis-owner');
        $ownerDemo = $this->user('keep-demo-owner');
        $staff = $this->user('keep-staff');

        $bisnis = $this->workspaceFor($ownerBisnis, 'keep-bisnis');
        $demo = $this->workspaceFor($ownerDemo, 'keep-demo');

        $this->inviteAndAccept($bisnis, $ownerBisnis, $staff);
        $this->inviteAndAccept($demo, $ownerDemo, $staff);

        // Nothing was revoked, so the fix must not have removed anything.
        $hub = $this->actingAs($staff->fresh())->get(route('tenants.index'))->assertOk()->getContent();
        $this->assertStringContainsString($bisnis->name, $hub);
        $this->assertStringContainsString($demo->name, $hub);
        $this->assertStringContainsString(route('tenants.switch', $bisnis), $hub);
        $this->assertStringContainsString(route('tenants.switch', $demo), $hub);

        // The owner of a workspace keeps full visibility of it.
        $ownerHub = $this->actingAs($ownerBisnis)->get(route('tenants.index'))->assertOk()->getContent();
        $this->assertStringContainsString($bisnis->name, $ownerHub);
    }

    // ------------------------------------------------- Dismissing the /tenants notice

    public function test_the_revoked_workspace_notice_can_be_dismissed_for_good(): void
    {
        $owner = $this->user('dismiss-owner');
        $staff = $this->user('dismiss-staff');
        $tenant = $this->workspaceFor($owner, 'dismiss-workspace');

        $membership = $this->inviteAndAccept($tenant, $owner, $staff);
        $this->remove($tenant, $owner, $membership);

        $html = $this->actingAs($staff)->get(route('tenants.index'))->assertOk()->getContent();
        $this->assertStringContainsString('You no longer have access to your previous workspace.', $html);
        $this->assertStringContainsString(route('tenants.notices.dismiss'), $html);

        $this->actingAs($staff)
            ->post(route('tenants.notices.dismiss'), [
                'key' => [TenantController::revokedNoticeKey($tenant->id)],
            ])
            ->assertRedirect(route('tenants.index'));

        $this->assertDatabaseHas('dismissed_notifications', [
            'user_id' => $staff->id,
            'key' => TenantController::revokedNoticeKey($tenant->id),
        ]);

        $after = $this->actingAs($staff)->get(route('tenants.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('You no longer have access to your previous workspace.', $after);
    }

    public function test_a_dismissed_notice_stays_hidden_across_sessions(): void
    {
        $owner = $this->user('session-owner');
        $staff = $this->user('session-staff');
        $tenant = $this->workspaceFor($owner, 'session-workspace');

        $membership = $this->inviteAndAccept($tenant, $owner, $staff);
        $this->remove($tenant, $owner, $membership);

        $this->actingAs($staff)
            ->post(route('tenants.notices.dismiss'), [
                'key' => [TenantController::revokedNoticeKey($tenant->id)],
            ])->assertRedirect();

        // Sign out and back in: the state lives in the database, not in the session, so
        // the notice must not come back.
        $this->post('/logout');
        $this->assertGuest();

        $html = $this->actingAs($staff->fresh())->get(route('tenants.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('You no longer have access to your previous workspace.', $html);
        $this->assertStringContainsString('No Workspace Available', $html);
    }

    public function test_a_new_revocation_raises_a_fresh_notice_after_an_earlier_dismissal(): void
    {
        $ownerOne = $this->user('fresh-owner-one');
        $ownerTwo = $this->user('fresh-owner-two');
        $staff = $this->user('fresh-staff');

        $one = $this->workspaceFor($ownerOne, 'notice-one');
        $two = $this->workspaceFor($ownerTwo, 'notice-two');

        $membershipOne = $this->inviteAndAccept($one, $ownerOne, $staff);
        $membershipTwo = $this->inviteAndAccept($two, $ownerTwo, $staff);

        $this->remove($one, $ownerOne, $membershipOne);
        $this->actingAs($staff)->post(route('tenants.notices.dismiss'), [
            'key' => [TenantController::revokedNoticeKey($one->id)],
        ])->assertRedirect();

        // Dismissal is per workspace: losing a different one must still be reported.
        $this->remove($two, $ownerTwo, $membershipTwo);

        $html = $this->actingAs($staff)->get(route('tenants.index'))->assertOk()->getContent();
        $this->assertStringContainsString('You no longer have access to your previous workspace.', $html);
        $this->assertStringContainsString($two->name, $html);

        // The dismissed workspace is gone from the notice: its key is what the page
        // submits for the close button, and that key is no longer there.
        $this->assertStringNotContainsString(TenantController::revokedNoticeKey($one->id), $html);
        $this->assertStringContainsString(TenantController::revokedNoticeKey($two->id), $html);
    }

    public function test_a_notice_cannot_be_dismissed_for_a_workspace_the_account_never_lost(): void
    {
        $owner = $this->user('forge-owner');
        $staff = $this->user('forge-staff');
        $tenant = $this->workspaceFor($owner, 'forge-workspace');

        $membership = $this->inviteAndAccept($tenant, $owner, $staff);

        // The membership is still active: the endpoint must refuse the key instead of
        // storing junk rows on demand.
        $this->actingAs($staff)
            ->post(route('tenants.notices.dismiss'), [
                'key' => [TenantController::revokedNoticeKey($tenant->id)],
            ])->assertStatus(422);

        $this->assertDatabaseCount('dismissed_notifications', 0);
        $this->assertNotNull($membership->fresh());
    }

    public function test_restoring_a_member_clears_the_notice_dismissal(): void
    {
        $owner = $this->user('restore-notice-owner');
        $staff = $this->user('restore-notice-staff');
        $tenant = $this->workspaceFor($owner, 'restore-notice');

        $membership = $this->inviteAndAccept($tenant, $owner, $staff);
        $this->remove($tenant, $owner, $membership);

        $this->actingAs($staff)->post(route('tenants.notices.dismiss'), [
            'key' => [TenantController::revokedNoticeKey($tenant->id)],
        ])->assertRedirect();

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->patch(route('team.restore', $membership))->assertRedirect();

        $this->assertDatabaseMissing('dismissed_notifications', [
            'user_id' => $staff->id,
            'key' => TenantController::revokedNoticeKey($tenant->id),
        ]);

        // Removed again later, the notice must be reported instead of staying silent.
        $this->remove($tenant, $owner, $membership->fresh());

        $html = $this->actingAs($staff)->get(route('tenants.index'))->assertOk()->getContent();
        $this->assertStringContainsString('You no longer have access to your previous workspace.', $html);
    }

    // ------------------------------------------------- Permanent deletion of a membership

    public function test_the_owner_can_permanently_delete_a_removed_membership(): void
    {
        $owner = $this->user('force-owner');
        $staff = $this->user('force-staff');
        $tenant = $this->workspaceFor($owner, 'force-workspace');

        $membership = $this->inviteAndAccept($tenant, $owner, $staff);
        $this->remove($tenant, $owner, $membership);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->delete(route('team.force-destroy', $membership))
            ->assertRedirect()
            ->assertSessionHas('success');

        // The membership is gone for good and can no longer be restored...
        $this->assertNull(TenantUser::withTrashed()->find($membership->id));
        $this->assertDatabaseMissing('tenant_user', [
            'id' => $membership->id,
            'tenant_id' => $tenant->id,
            'user_id' => $staff->id,
        ]);

        // ...but the account itself is untouched: a user outlives its memberships.
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'email' => $staff->email]);

        $html = $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get('/team')->assertOk()->getContent();
        $this->assertStringNotContainsString(route('team.force-destroy', $membership), $html);
    }

    public function test_permanently_deleting_a_membership_is_restricted_to_the_owner(): void
    {
        $owner = $this->user('force-admin-owner');
        $admin = $this->user('force-admin');
        $staff = $this->user('force-admin-staff');

        // Granting an elevated role is a Pro entitlement, and only Pro/ Business can
        // hold an Admin in the first place.
        $tenant = $this->workspaceFor($owner, 'force-admin-workspace', 'pro');

        $this->inviteAndAccept($tenant, $owner, $admin, 'Admin');
        $membership = $this->inviteAndAccept($tenant, $owner, $staff);
        $this->remove($tenant, $owner, $membership);

        // An Admin holds manage_users (they can restore), but erasing the membership
        // history is the Owner's decision alone.
        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->delete(route('team.force-destroy', $membership))
            ->assertForbidden();

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->get('/team')->assertOk()
            ->assertDontSee(route('team.force-destroy', $membership), false);

        $this->assertNotNull(TenantUser::withTrashed()->find($membership->id));

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get('/team')->assertOk()
            ->assertSee(route('team.force-destroy', $membership), false);
    }

    public function test_a_membership_that_was_never_removed_cannot_be_permanently_deleted(): void
    {
        $owner = $this->user('guard-owner');
        $staff = $this->user('guard-staff');
        $tenant = $this->workspaceFor($owner, 'guard-workspace');

        $membership = $this->inviteAndAccept($tenant, $owner, $staff);

        // The endpoint only erases a REVOKED membership: an active member must go
        // through "Remove" so the history and the audit trail stay intact.
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->delete(route('team.force-destroy', $membership))
            ->assertStatus(422);

        $this->assertSame(TenantUser::STATUS_ACTIVE, $membership->fresh()->status);
    }

    public function test_a_removed_member_keeps_both_restore_and_permanent_delete_for_the_owner(): void
    {
        $owner = $this->user('both-owner');
        $staff = $this->user('both-staff');
        $tenant = $this->workspaceFor($owner, 'both-workspace');

        $membership = $this->inviteAndAccept($tenant, $owner, $staff);
        $this->remove($tenant, $owner, $membership);

        $html = $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get('/team')->assertOk()->getContent();

        $this->assertStringContainsString('Removed members', $html);
        $this->assertStringContainsString(route('team.restore', $membership), $html);
        $this->assertStringContainsString(route('team.force-destroy', $membership), $html);
        $this->assertStringContainsString('Delete Permanently', $html);

        // The destructive action asks first.
        $this->assertStringContainsString('This action cannot be undone.', $html);
        $this->assertStringContainsString('Cancel', $html);
    }

    // --------------------------------------------------- Revoked-notice presentation

    /** Removes a member and returns the HTML the removed account sees on /tenants. */
    private function revokedNoticeHtml(Tenant $tenant, User $owner, User $staff): string
    {
        $membership = $this->inviteAndAccept($tenant, $owner, $staff);
        $this->remove($tenant, $owner, $membership);

        return $this->actingAs($staff->fresh())->get(route('tenants.index'))->assertOk()->getContent();
    }

    public function test_the_close_control_sits_in_the_notice_title_row(): void
    {
        $owner = $this->user('close-owner');
        $staff = $this->user('close-staff');
        $html = $this->revokedNoticeHtml($this->workspaceFor($owner, 'close-workspace'), $owner, $staff);

        // The button is laid out as a flex sibling of the title, not floated with
        // `absolute`, which is what used to leave it hanging outside the card.
        $this->assertStringContainsString('flex items-start justify-between gap-3', $html);
        $this->assertStringNotContainsString('absolute right-2 top-2', $html);
        $this->assertStringContainsString('data-testid="dismiss-revoked-notice"', $html);
    }

    public function test_the_revoked_workspaces_use_a_chevron_instead_of_a_bullet(): void
    {
        $owner = $this->user('chevron-owner');
        $staff = $this->user('chevron-staff');
        $html = $this->revokedNoticeHtml($this->workspaceFor($owner, 'chevron-workspace'), $owner, $staff);

        // A bullet list is what the old notice used; the arrow reads as "used to be here".
        $this->assertStringNotContainsString('list-disc', $html);
        $this->assertStringContainsString('m8.25 4.5 7.5 7.5-7.5 7.5', $html);
    }

    public function test_dismissing_the_notice_says_closing_never_saving(): void
    {
        $owner = $this->user('closing-owner');
        $staff = $this->user('closing-staff');
        $html = $this->revokedNoticeHtml($this->workspaceFor($owner, 'closing-workspace'), $owner, $staff);

        $this->assertStringContainsString('Closing…', $html);
        $this->assertStringNotContainsString('Saving', $html);

        // The global submit-button listener owns the label of every other form; this
        // one opts out, otherwise an icon-only round button becomes a "Saving…" pill.
        $this->assertStringContainsString('data-busy-skip="1"', $html);
    }

    // ------------------------------------------------------ Upgrade banner alignment

    public function test_the_upgrade_banner_matches_the_container_of_the_page_below_it(): void
    {
        $owner = $this->user('banner-owner');
        $tenant = $this->workspaceFor($owner, 'banner-workspace');

        $upgradeSession = ['upgrade_required' => [
            'message' => 'Advanced Reports are available on Pro and Business plans.',
        ]];

        // A narrow page (/team renders at max-w-4xl) must not have a 7xl banner above it.
        $team = $this->actingAs($owner)->withSession($upgradeSession + ['tenant_id' => $tenant->id])
            ->get('/team')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="upgrade-required"', $team);
        $this->assertStringContainsString('max-w-4xl', $team);
        $this->assertStringNotContainsString('max-w-7xl', $team);

        // A wide page (dashboard renders at max-w-7xl) keeps the 7xl banner.
        $dashboard = $this->actingAs($owner)->withSession($upgradeSession + ['tenant_id' => $tenant->id])
            ->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('max-w-7xl', $dashboard);
    }

    public function test_the_upgrade_banner_adds_no_margin_of_its_own(): void
    {
        $owner = $this->user('banner-margin-owner');
        $tenant = $this->workspaceFor($owner, 'banner-margin-workspace');

        $html = $this->actingAs($owner)->withSession([
            'tenant_id' => $tenant->id,
            'upgrade_required' => ['message' => 'Advanced Reports are available on Pro and Business plans.'],
        ])->get('/dashboard')->assertOk()->getContent();

        // The shell wrapper spaces its children with `space-y-6`; a `mb-6` on the
        // banner stacked on top of it and pushed the content too far down.
        $this->assertStringNotContainsString('mx-auto mb-6 w-full', $html);
    }

    public function test_the_upgrade_banner_itself_adds_no_extra_spacing(): void
    {
        $owner = $this->user('banner-gap-owner');
        $tenant = $this->workspaceFor($owner, 'banner-gap-workspace');

        $html = $this->actingAs($owner)->withSession([
            'tenant_id' => $tenant->id,
            'upgrade_required' => ['message' => 'Advanced Reports are available on Pro and Business plans.'],
        ])->get('/dashboard')->assertOk()->getContent();

        // Neither side may add a gap of its own: the page's `py-12` is the single
        // source of the space below the banner. A margin here (or a `space-y-*` on the
        // shell wrapper) used to stack with it and leave a 72px hole.
        $this->assertStringNotContainsString('mb-6 w-full', $html);
        $this->assertStringContainsString('pt-6 sm:px-6 lg:px-8', $html);
        $this->assertStringNotContainsString('min-w-0 space-y-6 px-4 sm:px-0', $html);
    }

    public function test_the_upgrade_banner_box_lines_up_with_the_cards_below(): void
    {
        $owner = $this->user('banner-box-owner');
        $tenant = $this->workspaceFor($owner, 'banner-box-workspace');

        $html = $this->actingAs($owner)->withSession([
            'tenant_id' => $tenant->id,
            'upgrade_required' => ['message' => 'Advanced Reports are available on Pro and Business plans.'],
        ])->get('/dashboard')->assertOk()->getContent();

        // The horizontal inset that positions the cards has to sit on a transparent
        // wrapper, not on the coloured border box — otherwise the amber rectangle is
        // 32px wider on each side than every card under it.
        $this->assertMatchesRegularExpression(
            '/<div class="mx-auto w-full max-w-7xl pt-6 sm:px-6 lg:px-8">\s*<section[^>]*rounded-xl border border-amber-300 bg-amber-50 p-4 sm:p-5"/',
            $html
        );

        // No horizontal padding left on the coloured box itself.
        $this->assertStringNotContainsString('bg-amber-50 p-4 sm:p-5 sm:px-6 lg:px-8', $html);
    }
}



