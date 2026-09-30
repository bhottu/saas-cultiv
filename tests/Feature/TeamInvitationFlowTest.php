<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Team invitations require an explicit Accept/Reject by the recipient.
 *
 * The membership table, notification system and roles are the EXISTING ones —
 * this suite pins the incremental behaviour: `invited` grants no workspace
 * access, only the recipient may respond, Accept activates, Reject never does,
 * and the pending invitation surfaces on /team, the dashboard and the
 * notification bell.
 */
class TeamInvitationFlowTest extends TestCase
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

    /** Workspace on the Starter plan (5 seats) so inviting is within quota. */
    private function workspaceFor(User $owner, string $slug, string $planSlug = 'starter'): Tenant
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'owner_id' => $owner->id,
        ]);

        $tenant->users()->attach($owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
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

    private function invite(Tenant $tenant, User $owner, User $invitee, string $role = 'Staff'): TenantUser
    {
        $this->actingAs($owner)
            ->withSession(['tenant_id' => $tenant->id])
            ->post('/team/invite', ['email' => $invitee->email, 'role' => $role])
            ->assertRedirect()
            ->assertSessionHas('success');

        return TenantUser::where('tenant_id', $tenant->id)
            ->where('user_id', $invitee->id)
            ->firstOrFail();
    }


    // ------------------------------------------------------------------ pending state

    public function test_invite_creates_a_pending_membership_a_notification_and_no_access(): void
    {
        $owner = $this->user('inv-owner');
        $invitee = $this->user('inv-invitee');
        $tenant = $this->workspaceFor($owner, 'inv-workspace');

        $membership = $this->invite($tenant, $owner, $invitee);

        $this->assertSame('invited', $membership->status);
        $this->assertNotNull($membership->invitation_token);
        $this->assertSame($owner->id, $membership->invited_by);

        // A pending invitation is NOT a membership: no workspace access at all. The
        // invitee is routed to the workspace hub — never a hard 403 — where the pending
        // invitation can be answered.
        $this->assertNull($invitee->membershipIn($tenant));
        $this->actingAs($invitee)->withSession(['tenant_id' => $tenant->id])
            ->get('/dashboard')
            ->assertRedirect(route('tenants.index'));

        // The forged/stale pointer is dropped instead of resolving forever to nothing.
        $this->assertNull($invitee->fresh()->current_tenant_id);

        // The EXISTING database notification channel carries the invitation…
        $notification = $invitee->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame(TeamInvitationNotification::class, $notification->type);
        // Stored data is path-only (ngrok-safe); the bell prefixes the app URL at render.
        $this->assertSame(route('team.index', [], false), $notification->data['url']);
        $this->assertStringContainsString($owner->name, $notification->data['message']);
        $this->assertStringContainsString($tenant->name, $notification->data['message']);
        $this->assertStringContainsString('Staff', $notification->data['message']);
    }

    public function test_a_workspaceless_invitee_lands_on_the_hub_which_answers_the_invitation(): void
    {
        $owner = $this->user('pend-owner');
        $invitee = $this->user('pend-invitee');
        $tenant = $this->workspaceFor($owner, 'pend-workspace');
        $membership = $this->invite($tenant, $owner, $invitee);

        // /team needs a workspace, so an account without one is sent to the hub…
        $this->actingAs($invitee)->get('/team')->assertRedirect(route('tenants.index'));

        // …where the SAME pending invitation renders: the invitee can always respond.
        $html = $this->actingAs($invitee)->get(route('tenants.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Pending Invitations', $html);
        $this->assertStringContainsString($tenant->name, $html);
        $this->assertStringContainsString(route('team.invitations.accept', $membership), $html);
        $this->assertStringContainsString(route('team.invitations.reject', $membership), $html);
        $this->assertStringContainsString('data-busy-label="Accepting…"', $html);
        $this->assertStringContainsString('data-busy-label="Rejecting…"', $html);
    }

    public function test_dashboard_shows_the_pending_invitation_with_accept_and_reject(): void
    {
        $owner = $this->user('dash-owner');
        $invitee = $this->user('dash-invitee');
        $tenant = $this->workspaceFor($owner, 'dash-workspace');
        $membership = $this->invite($tenant, $owner, $invitee);

        // Give the invitee their own workspace so the dashboard (tenant-scoped) renders.
        $inviteeWorkspace = $this->workspaceFor($invitee, 'invitee-workspace');

        $html = $this->actingAs($invitee)
            ->withSession(['tenant_id' => $inviteeWorkspace->id])
            ->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('Pending Invitations', $html);
        $this->assertStringContainsString(route('team.invitations.accept', $membership), $html);
        $this->assertStringContainsString(route('team.invitations.reject', $membership), $html);
    }

    public function test_the_notification_bell_links_the_invitation_to_the_team_page(): void
    {
        $owner = $this->user('bell-owner');
        $invitee = $this->user('bell-invitee');
        $tenant = $this->workspaceFor($owner, 'bell-workspace');
        $this->invite($tenant, $owner, $invitee);

        $inviteeWorkspace = $this->workspaceFor($invitee, 'bell-invitee-ws');

        // The bell renders stored links via url() (path-only data stays ngrok-safe).
        $html = $this->actingAs($invitee)
            ->withSession(['tenant_id' => $inviteeWorkspace->id])
            ->get('/dashboard')->assertOk()->getContent();

        // The header dropdown row for this notification is a real link to /team.
        $this->assertStringContainsString(url(route('team.index', [], false)), $html);
        $this->assertStringContainsString('invited you to join', $html);
    }

    // ------------------------------------------------------------------ accept / reject

    public function test_accepting_activates_the_membership_and_grants_workspace_access(): void
    {
        $owner = $this->user('acc-owner');
        $invitee = $this->user('acc-invitee');
        $tenant = $this->workspaceFor($owner, 'acc-workspace');
        $membership = $this->invite($tenant, $owner, $invitee);

        $this->actingAs($invitee)
            ->post(route('team.invitations.accept', $membership))
            ->assertRedirect()
            ->assertSessionHas('success');

        $membership->refresh();
        $this->assertSame('active', $membership->status);
        $this->assertNotNull($membership->joined_at);
        $this->assertNull($membership->invitation_token);

        // The reserved seat is activated in place: no second membership row.
        $this->assertSame(1, TenantUser::where('tenant_id', $tenant->id)
            ->where('user_id', $invitee->id)->count());
        $this->assertNotNull($invitee->fresh()->membershipIn($tenant));

        // ...and the workspace is now genuinely reachable, without the invite nag.
        $html = $this->actingAs($invitee->fresh())
            ->withSession(['tenant_id' => $tenant->id])
            ->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('Pending Invitations', $html);
    }

    public function test_rejecting_an_invitation_never_grants_workspace_access(): void
    {
        $owner = $this->user('rej-owner');
        $invitee = $this->user('rej-invitee');
        $tenant = $this->workspaceFor($owner, 'rej-workspace');
        $membership = $this->invite($tenant, $owner, $invitee);

        $this->actingAs($invitee)
            ->post(route('team.invitations.reject', $membership))
            ->assertRedirect()
            ->assertSessionHas('success');

        $membership->refresh();
        $this->assertSame('rejected', $membership->status);
        $this->assertNull($membership->invitation_token);
        $this->assertNull($membership->joined_at);
        $this->assertNull($invitee->fresh()->membershipIn($tenant));

        // A rejected invitation is not an account problem: the account is sent to the
        // workspace hub (which explains the state) instead of a hard 403.
        $this->actingAs($invitee->fresh())
            ->withSession(['tenant_id' => $tenant->id])
            ->get('/dashboard')->assertRedirect(route('tenants.index'));
    }

    public function test_a_rejected_invitation_can_be_resent_and_then_accepted(): void
    {
        $owner = $this->user('again-owner');
        $invitee = $this->user('again-invitee');
        $tenant = $this->workspaceFor($owner, 'again-workspace');
        $membership = $this->invite($tenant, $owner, $invitee);

        $this->actingAs($invitee)->post(route('team.invitations.reject', $membership))->assertRedirect();

        // Re-inviting reuses the SAME membership row so seat bookkeeping and the
        // membership history stay single-sourced.
        $resent = $this->invite($tenant, $owner, $invitee);
        $this->assertSame($membership->id, $resent->id);
        $this->assertSame('invited', $resent->status);
        $this->assertNotNull($resent->invitation_token);
        $this->assertSame(1, TenantUser::where('tenant_id', $tenant->id)
            ->where('user_id', $invitee->id)->count());

        $this->actingAs($invitee)
            ->post(route('team.invitations.accept', $membership))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('active', $membership->fresh()->status);
    }

    public function test_only_the_recipient_may_respond_and_only_while_pending(): void
    {
        $owner = $this->user('guard-owner');
        $invitee = $this->user('guard-invitee');
        $stranger = $this->user('guard-stranger');
        $tenant = $this->workspaceFor($owner, 'guard-workspace');
        $membership = $this->invite($tenant, $owner, $invitee);

        // Request ids are never trusted: a different account cannot answer for me.
        $this->actingAs($stranger)
            ->post(route('team.invitations.accept', $membership))->assertForbidden();
        $this->actingAs($stranger)
            ->post(route('team.invitations.reject', $membership))->assertForbidden();
        $this->assertSame('invited', $membership->fresh()->status);

        // An answered invitation stays answered — replaying the form changes nothing.
        $this->actingAs($invitee)
            ->post(route('team.invitations.accept', $membership))->assertRedirect();
        $this->actingAs($invitee)
            ->post(route('team.invitations.accept', $membership))->assertNotFound();
        $this->actingAs($invitee)
            ->post(route('team.invitations.reject', $membership))->assertNotFound();
        $this->assertSame('active', $membership->fresh()->status);
    }

    public function test_the_signed_email_link_accepts_but_an_unsigned_one_does_not(): void
    {
        $owner = $this->user('signed-owner');
        $invitee = $this->user('signed-invitee');
        $tenant = $this->workspaceFor($owner, 'signed-workspace');
        $membership = $this->invite($tenant, $owner, $invitee);

        // An unsigned / tampered link is rejected before any state changes.
        $this->actingAs($invitee)->get(route('team.accept', $membership))->assertForbidden();
        $this->assertSame('invited', $membership->fresh()->status);

        $this->actingAs($invitee)
            ->get(URL::signedRoute('team.accept', ['membership' => $membership->id]))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success');

        $this->assertSame('active', $membership->fresh()->status);
    }

    public function test_accepting_adopts_the_invited_workspace_for_a_brand_new_account(): void
    {
        $owner = $this->user('adopt-owner');
        $invitee = $this->user('adopt-invitee');
        $tenant = $this->workspaceFor($owner, 'adopt-workspace');
        $membership = $this->invite($tenant, $owner, $invitee);

        $this->assertNull($invitee->current_tenant_id);

        $this->actingAs($invitee)
            ->post(route('team.invitations.accept', $membership))->assertRedirect();

        // The next page load must have a valid tenant context.
        $this->assertSame($tenant->id, $invitee->fresh()->current_tenant_id);
    }

    public function test_accepting_does_not_move_an_active_member_off_their_own_workspace(): void
    {
        $owner = $this->user('keep-owner');
        $invitee = $this->user('keep-invitee');
        $tenant = $this->workspaceFor($owner, 'keep-workspace');
        $inviteeWorkspace = $this->workspaceFor($invitee, 'keep-own-workspace');
        $membership = $this->invite($tenant, $owner, $invitee);

        $this->actingAs($invitee)
            ->withSession(['tenant_id' => $inviteeWorkspace->id])
            ->post(route('team.invitations.accept', $membership))->assertRedirect();

        $invitee->refresh();
        $this->assertSame($inviteeWorkspace->id, $invitee->current_tenant_id);
        // Both memberships are active; the invited one is simply available to switch to.
        $this->assertNotNull($invitee->membershipIn($tenant));
        $this->assertNotNull($invitee->membershipIn($inviteeWorkspace));
    }
}
