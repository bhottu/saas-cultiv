<?php

namespace App\Http\Controllers;

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
        $ctx->check();

        return view('team.index', [
            'members' => $ctx->tenant()->memberships()->with('user')->orderBy('joined_at')->orderBy('id')->get(),
            'roles' => array_keys(config('permissions.roles')),
            'activeCount' => $ctx->tenant()->seatCount(),
            'seatLimit' => $this->usage->limit($ctx->tenant(), 'max_users'),
            'canManageUsers' => $ctx->userCan('manage_users'),
            'canManageRoles' => $ctx->userCan('manage_roles'),
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
            'role' => ['required', 'in:Admin,Manager,Staff,Viewer'], // Owner is never assignable here
        ]);

        $tenant = $ctx->tenant();
        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return back()->withErrors(['email' => 'No account with that email. Ask them to register first, then invite.']);
        }

        if ($user->membershipIn($tenant)) {
            return back()->withErrors(['email' => 'This user is already a member of this workspace.']);
        }

        $membership = TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $data['role'],
            'status' => 'invited',
            'invitation_token' => Str::random(40),
            'invited_by' => $request->user()->id,
        ]);

        $user->notify(new TeamInvitationNotification($membership));
        AuditLogger::log('user.invited', $membership, ['email' => $data['email'], 'role' => $data['role']]);

        return back()->with('success', "Invitation sent to {$data['email']} ({$data['role']}).");
    }

    /** Signed-link acceptance — server validates the invitee identity + seat limit. */
    public function accept(Request $request, TenantUser $membership)
    {
        abort_unless($membership->status === 'invited' && $membership->invitation_token, 404, 'Invitation not found.');
        abort_unless($request->user()->id === $membership->user_id, 403, 'This invitation belongs to a different account.');

        $tenant = $membership->tenant;

        // Plan seat limit is enforced server-side at the moment of joining.
        app(UsageService::class)->enforceSeat($tenant);

        $membership->update(['status' => 'active', 'joined_at' => now(), 'invitation_token' => null]);
        AuditLogger::log('user.joined', $membership, ['email' => $request->user()->email]);

        if (! $request->user()->current_tenant_id) {
            $request->user()->forceFill(['current_tenant_id' => $tenant->id])->save();
            session(['tenant_id' => $tenant->id]);
        }

        return redirect()->route('dashboard')->with('success', "Welcome to {$tenant->name}!");
    }

    public function updateRole(Request $request, $membershipId)
    {
        $ctx = app('tenant.context');
        $ctx->check();
        $ctx->authorize('manage_roles');

        $membership = TenantUser::findOrFail($membershipId);
        abort_unless($membership->tenant_id === $ctx->tenant()->id, 404);
        abort_unless($membership->role !== 'Owner', 403, 'The Owner role cannot be changed here.');

        $data = $request->validate(['role' => ['required', 'in:Admin,Manager,Staff,Viewer']]);
        $membership->update(['role' => $data['role']]);

        AuditLogger::log('user.role_changed', $membership, ['to' => $data['role']]);

        return back()->with('success', 'Role updated.');
    }

    public function remove(Request $request, $membershipId)
    {
        $ctx = app('tenant.context');
        $ctx->check();
        $ctx->authorize('manage_users');

        $membership = TenantUser::findOrFail($membershipId);
        abort_unless($membership->tenant_id === $ctx->tenant()->id, 404);
        abort_unless($membership->role !== 'Owner', 403, 'The Owner cannot be removed.');

        $userId = $membership->user_id;
        $membership->delete();

        AuditLogger::log('user.removed', $membership);

        // Clear stale tenant pointer for the removed user.
        User::where('id', $userId)->where('current_tenant_id', $ctx->tenant()->id)
            ->update(['current_tenant_id' => null]);

        return back()->with('success', 'Member removed.');
    }
}
