<?php

namespace App\Http\Controllers;

use App\Models\DismissedNotification;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\AuditLogger;
use App\Services\UsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Team management: invite existing users, set roles, remove members (§6, §22). */
class TeamController extends Controller
{
    public function __construct(private readonly UsageService $usage) {}

    public function index(Request $request)
    {
        $ctx = app('tenant.context');

        // Invitations addressed to ME — shown even without an active workspace,
        // so an invitee who lands on /team from a notification can always respond.
        $pendingInvitations = TenantUser::query()
            ->with(['tenant:id,name', 'invitedBy:id,name'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'invited')
            ->latest()
            ->get();

        // No active workspace context: show the pending invitations only, never a
        // members list or invite form belonging to somebody else's workspace.
        if ($ctx->tenant() === null) {
            return view('team.index', [
                'members' => collect(),
                'roles' => array_keys(config('permissions.roles')),
                'activeCount' => 0,
                'occupiedCount' => 0,
                'seatLimit' => null,
                'canManageUsers' => false,
                'canManageRoles' => false,
                'pendingInvitations' => $pendingInvitations,
                'removedMembers' => collect(),
                'isWorkspaceOwner' => false,
                'showWorkspace' => false,
            ]);
        }

        $ctx->check();

        return view('team.index', [
            'members' => $ctx->tenant()->memberships()->with('user')
                ->whereIn('status', TenantUser::SEAT_STATUSES)
                ->orderBy('joined_at')->orderBy('id')->get(),
            'roles' => array_keys(config('permissions.roles')),
            'activeCount' => $ctx->tenant()->seatCount(),
            'occupiedCount' => $ctx->tenant()->occupiedSeatCount(),
            'seatLimit' => $this->usage->limit($ctx->tenant(), 'max_users'),
            'canManageUsers' => $ctx->userCan('manage_users'),
            'canManageRoles' => $ctx->userCan('manage_roles')
                && $this->usage->allows($ctx->tenant(), 'advanced_permissions'),
            'pendingInvitations' => $pendingInvitations,
            // Revoked memberships are kept as history (soft-deleted). Only a user who
            // can manage the team sees them, and only they can restore one.
            'removedMembers' => $ctx->userCan('manage_users')
                ? $ctx->tenant()->memberships()->onlyTrashed()
                    ->where('status', TenantUser::STATUS_REMOVED)
                    ->with('user')
                    ->latest('deleted_at')
                    ->get()
                : collect(),
            // Erase a membership permanently is an Owner-level decision, so it is kept
            // separate from `manage_users` (which Admins also hold). The endpoint
            // re-checks it server-side; this only decides whether the button renders.
            'isWorkspaceOwner' => $ctx->role() === config('permissions.owner_role'),
            'showWorkspace' => true,
        ]);
    }

    /** Invite an existing user by email (unregistered emails are rejected). */
    public function invite(Request $request)
    {
        $ctx = app('tenant.context');
        $ctx->check();
        $ctx->authorize('manage_users');

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:Admin,Manager,Staff,Viewer'],
        ]);

        $tenant = $ctx->tenant();
        $user = User::where('email', $data['email'])->first();

        if (in_array($data['role'], ['Admin', 'Manager'], true)) {
            $this->usage->enforceFeature($tenant, 'advanced_permissions');
        }

        if (! $user) {
            return back()->withErrors(['email' => 'No account with that email. Ask them to register first, then invite.']);
        }

        // One row per (workspace, account): a previously REJECTED invitation may be
        // re-sent and a REMOVED member may be invited back, so the lookup includes
        // soft-deleted rows. Reusing them keeps the unique (tenant_id, user_id) pair
        // and the membership history intact instead of inserting a duplicate.
        $existing = $tenant->memberships()->withTrashed()->where('user_id', $user->id)->first();

        if ($existing && ! $existing->trashed() && in_array($existing->status, TenantUser::SEAT_STATUSES, true)) {
            return back()->withErrors(['email' => 'This user is already a member or has a pending invitation in this workspace.']);
        }

        $membership = \Illuminate\Support\Facades\DB::transaction(function () use ($tenant, $user, $data, $request, $existing) {
            $this->usage->enforceSeat($tenant);

            if ($existing) {
                // Revive the row: clearing deleted_at puts the membership back in scope
                // for the seat counters and access checks, so the invite is a normal
                // pending invitation again.
                if ($existing->trashed()) {
                    $existing->restore();
                }

                $existing->update([
                    'role' => $data['role'],
                    'status' => TenantUser::STATUS_INVITED,
                    'invitation_token' => Str::random(40),
                    'invited_by' => $request->user()->id,
                    'joined_at' => null,
                ]);

                return $existing->refresh();
            }

            return TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => $data['role'],
                'status' => TenantUser::STATUS_INVITED,
                'invitation_token' => Str::random(40),
                'invited_by' => $request->user()->id,
            ]);
        });

        $user->notify(new TeamInvitationNotification($membership));
        AuditLogger::log('user.invited', $membership, ['email' => $data['email'], 'role' => $data['role']]);

        return back()->with('success', "Invitation sent to {$data['email']} ({$data['role']}).");
    }

    /** Signed-link acceptance — server validates the invitee identity. */
    public function accept(Request $request, TenantUser $membership)
    {
        $this->ensureOwnPendingInvitation($request, $membership);

        return $this->activate($request, $membership);
    }

    /** Accept from an authenticated surface (dashboard, /team). Recipient only. */
    public function acceptInvitation(Request $request, TenantUser $membership)
    {
        $this->ensureOwnPendingInvitation($request, $membership);

        return $this->activate($request, $membership);
    }

    /** Decline an invitation — the membership never becomes active. Recipient only. */
    public function rejectInvitation(Request $request, TenantUser $membership)
    {
        $this->ensureOwnPendingInvitation($request, $membership);

        $membership->update(['status' => TenantUser::STATUS_REJECTED, 'invitation_token' => null]);

        AuditLogger::log('user.invitation_rejected', $membership, ['email' => $request->user()->email]);

        return back()->with('success', 'Invitation rejected.');
    }

    /**
     * Only the invitation's recipient may see or act on it, and only while it is
     * still pending — request IDs are never trusted on their own.
     */
    private function ensureOwnPendingInvitation(Request $request, TenantUser $membership): void
    {
        abort_unless($membership->status === TenantUser::STATUS_INVITED && $membership->invitation_token, 404, 'Invitation not found.');
        abort_unless($request->user()->id === $membership->user_id, 403, 'This invitation belongs to a different account.');
    }

    private function activate(Request $request, TenantUser $membership)
    {
        $tenant = $membership->tenant;

        // The invitation already reserves capacity; activating that same membership does
        // not consume another seat. Existing over-limit data is preserved, never deleted.
        $membership->update(['status' => TenantUser::STATUS_ACTIVE, 'joined_at' => now(), 'invitation_token' => null]);
        AuditLogger::log('user.joined', $membership, ['email' => $request->user()->email]);

        if (! $request->user()->current_tenant_id) {
            $request->user()->forceFill(['current_tenant_id' => $tenant->id])->save();
            session(['tenant_id' => $tenant->id]);
        }

        // Signed email links land with no browser history: keep the original
        // dashboard landing. In-app forms return to the page that hosted them.
        $message = "Welcome to {$tenant->name}!";

        return $request->isMethod('post')
            ? back()->with('success', $message)
            : redirect()->route('dashboard')->with('success', $message);
    }

    public function updateRole(Request $request, $membershipId)
    {
        $ctx = app('tenant.context');
        $ctx->check();
        $ctx->authorize('manage_roles');
        $this->usage->enforceFeature($ctx->tenant(), 'advanced_permissions');

        $membership = TenantUser::findOrFail($membershipId);
        abort_unless($membership->tenant_id === $ctx->tenant()->id, 404);
        abort_unless($membership->role !== 'Owner', 403, 'The Owner role cannot be changed here.');

        $data = $request->validate(['role' => ['required', 'in:Admin,Manager,Staff,Viewer']]);
        $membership->update(['role' => $data['role']]);

        AuditLogger::log('user.role_changed', $membership, ['to' => $data['role']]);

        return back()->with('success', 'Role updated.');
    }

    /**
     * Remove a member from the team.
     *
     * ONLY the membership ends: the user account is never touched, so the person can
     * still sign in and will land on the workspace hub instead of a 403. The row stays
     * in tenant_user as history (status 'removed' + deleted_at), which also keeps the
     * (tenant_id, user_id) pair reserved for a later re-invite or restore.
     */
    public function remove(Request $request, $membershipId)
    {
        $ctx = app('tenant.context');
        $ctx->check();
        $ctx->authorize('manage_users');

        $membership = TenantUser::findOrFail($membershipId);
        abort_unless($membership->tenant_id === $ctx->tenant()->id, 404);
        abort_unless($membership->role !== 'Owner', 403, 'The Owner cannot be removed.');

        $userId = $membership->user_id;
        $email = $membership->user?->email;
        $role = $membership->role;

        $membership->update(['status' => TenantUser::STATUS_REMOVED, 'invitation_token' => null]);
        $membership->delete(); // Soft delete: no data and no account is destroyed.

        AuditLogger::log('user.removed', $membership, ['email' => $email, 'role' => $role]);

        // Clear stale tenant pointer for the removed user, so their next request goes to
        // the workspace hub rather than resolving to a workspace they no longer belong to.
        User::where('id', $userId)->where('current_tenant_id', $ctx->tenant()->id)
            ->update(['current_tenant_id' => null]);

        return back()->with('success', 'Member removed. Their account is unchanged.');
    }

    /**
     * Re-add a member whose membership was revoked earlier.
     *
     * Same authorization as inviting, and restoring consumes a seat exactly like an
     * invitation does, so a revoked seat can never silently exceed the plan.
     */
    public function restore(Request $request, $membershipId)
    {
        $ctx = app('tenant.context');
        $ctx->check();
        $ctx->authorize('manage_users');

        $membership = TenantUser::withTrashed()->findOrFail($membershipId);
        abort_unless($membership->tenant_id === $ctx->tenant()->id, 404);
        abort_unless(
            $membership->trashed() && $membership->status === TenantUser::STATUS_REMOVED,
            422,
            'That membership is not removed.'
        );

        // Restoring an elevated role is the same entitlement as inviting one.
        if (in_array($membership->role, ['Admin', 'Manager'], true)) {
            $this->usage->enforceFeature($ctx->tenant(), 'advanced_permissions');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($membership, $ctx) {
            $this->usage->enforceSeat($ctx->tenant());

            $membership->restore();
            $membership->update([
                'status' => TenantUser::STATUS_ACTIVE,
                'joined_at' => now(),
                'invitation_token' => null,
            ]);
        });

        AuditLogger::log('user.restored', $membership, [
            'email' => $membership->user?->email,
            'role' => $membership->role,
        ]);

        // The member is back in the workspace, so the "you no longer have access"
        // notice no longer applies. Forget its dismissal, otherwise a later removal
        // from this same workspace would stay silent forever.
        DismissedNotification::where('user_id', $membership->user_id)
            ->where('key', TenantController::revokedNoticeKey($membership->tenant_id))
            ->delete();

        return back()->with('success', 'Member restored.');
    }

    /**
     * Erase a revoked membership for good (Owner only).
     *
     * "Remove" ends access and keeps history; this is the deliberate clean-up on top of
     * it, for a workspace that should stop listing that person at all. It deletes the
     * tenant_user row itself — irreversibly — which also frees the (tenant_id, user_id)
     * pair for a future membership. The user account is NOT deleted: an account outlives
     * every workspace it was ever part of.
     */
    public function forceDelete(Request $request, $membershipId)
    {
        $ctx = app('tenant.context');
        $ctx->check();

        // Stricter than `manage_users` (Admins hold that too): erasing a membership is
        // the Owner's call alone, and the UI only shows the button to the Owner.
        abort_unless(
            $ctx->role() === config('permissions.owner_role'),
            403,
            'Only the workspace owner can do that.'
        );

        // The row is soft-deleted, so implicit binding can never find it: resolve it
        // explicitly and require that it really is a revoked membership.
        $membership = TenantUser::withTrashed()->findOrFail($membershipId);
        abort_unless($membership->tenant_id === $ctx->tenant()->id, 404);
        abort_unless(
            $membership->trashed() && $membership->status === TenantUser::STATUS_REMOVED,
            422,
            'That membership is not removed.'
        );
        abort_unless($membership->role !== config('permissions.owner_role'), 403, 'The Owner cannot be removed.');

        $userId = $membership->user_id;
        $email = $membership->user?->email;
        $role = $membership->role;

        AuditLogger::log('user.membership_deleted', $membership, ['email' => $email, 'role' => $role]);

        // The membership is what is cleaned up; the account survives untouched.
        $membership->forceDelete();

        // The person can no longer be told they lost access to this workspace, so the
        // notice about it is meaningless — drop their dismissal record too.
        DismissedNotification::where('user_id', $userId)
            ->where('key', TenantController::revokedNoticeKey($membership->tenant_id))
            ->delete();

        return back()->with('success', 'Membership deleted permanently. The user account was not affected.');
    }
}
