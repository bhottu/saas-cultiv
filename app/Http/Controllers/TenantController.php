<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\DismissedNotification;
use App\Models\TenantUser;
use App\Services\SubscriptionService;
use App\Services\UsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    public function __construct(private readonly UsageService $usage) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $ownedCount = $user->ownedTenants()->where('status', 'active')->count();

        // Invitations addressed to this account. The workspace hub is exactly where an
        // invitee with no workspace ends up (EnsureTenantContext sends them here), so the
        // accept/reject controls have to render here as well — not only on /team.
        $pendingInvitations = TenantUser::query()
            ->with(['tenant:id,name', 'invitedBy:id,name'])
            ->where('user_id', $user->id)
            ->where('status', TenantUser::STATUS_INVITED)
            ->latest()
            ->get();

        // Memberships revoked earlier through the team page. They are kept as history, and
        // they are what lets this page explain "you no longer have access to your previous
        // workspace" instead of leaving the account on an unexplained 403.
        $removedWorkspaces = $user->removedMemberships()->with('tenant:id,name')->get();

        // A notice the account already closed is never shown again. Dismissing is stored
        // per workspace, so revoking a DIFFERENT workspace later raises a fresh notice
        // instead of staying silent forever.
        $dismissed = DismissedNotification::where('user_id', $user->id)
            ->whereIn('key', $removedWorkspaces->map(fn ($m) => static::revokedNoticeKey($m->tenant_id)))
            ->pluck('key')
            ->all();

        $revokedNotices = $removedWorkspaces
            ->reject(fn ($m) => in_array(static::revokedNoticeKey($m->tenant_id), $dismissed, true))
            ->values()
            ->map(fn ($m) => [
                'key' => static::revokedNoticeKey($m->tenant_id),
                'name' => $m->tenant?->name ?? __('a workspace that has been closed'),
            ]);

        return view('tenants.index', [
            'tenants' => $user->activeTenants()->orderBy('tenants.name')->get(),
            'ownedCount' => $ownedCount,
            'workspaceLimit' => $this->usage->workspaceLimitFor($user),
            // Drives the per-row Edit/Delete visibility; membership alone is not
            // enough to manage a workspace.
            'ownerTenantIds' => $user->ownedTenants()->pluck('id'),
            'pendingInvitations' => $pendingInvitations,
            'revokedNotices' => $revokedNotices,
        ]);
    }

    /**
     * Stable identity of the "you no longer have access" notice for one workspace.
     *
     * Keyed by tenant so the notice is dismissed once per workspace, and so a future
     * revocation (another workspace, or the same one after a restore) is a new key and
     * therefore still reported.
     */
    public static function revokedNoticeKey(int $tenantId): string
    {
        return "workspace_access_revoked:{$tenantId}";
    }

    /**
     * Permanently hide a workspace notice for this account.
     *
     * Stored server-side, so the state survives a refresh, a logout and a new sign-in.
     * The key is not trusted blindly: the account must really hold a revoked
     * membership for that workspace, otherwise the endpoint would happily write
     * arbitrary rows.
     */
    public function dismissNotice(Request $request)
    {
        // The view submits one key per notice currently on screen, so a single close
        // click retires them all.
        $data = $request->validate([
            'key' => ['required', 'array', 'min:1'],
            'key.*' => ['required', 'string', 'max:100', 'regex:/^workspace_access_revoked:\d+$/'],
        ]);

        $user = $request->user();
        $dismissed = 0;

        foreach ($data['key'] as $key) {
            $tenantId = (int) str($key)->after(':')->toString();

            // The key is not trusted blindly: the account must really hold a revoked
            // membership for that workspace, otherwise the endpoint would happily
            // write arbitrary rows.
            if (! $user->removedMemberships()->where('tenant_id', $tenantId)->exists()) {
                continue;
            }

            DismissedNotification::updateOrCreate(
                ['user_id' => $user->id, 'key' => $key],
                ['dismissed_at' => now()]
            );

            $dismissed++;
        }

        abort_if($dismissed === 0, 422, 'No revoked membership to dismiss.');

        // The notice posts this with fetch() to fade out in place, so it gets JSON
        // instead of a redirect it would have to follow and throw away.
        if ($request->expectsJson()) {
            return response()->json(['dismissed' => $dismissed]);
        }

        return redirect()->route('tenants.index');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $tenant = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $data) {
            $this->usage->enforceWorkspaceCreation($request->user());

            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(6)),
                'owner_id' => $request->user()->id,
                'status' => 'active',
            ]);

            $tenant->users()->attach($request->user()->id, [
                'role' => 'Owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            // Start on the free plan.
            $free = Plan::where('is_free_tier', true)->first();
            if ($free) {
                app(SubscriptionService::class)->switchToFree($tenant);
            }

            return $tenant;
        });

        \App\Services\AuditLogger::log('tenant.created', $tenant);

        $request->user()->forceFill(['current_tenant_id' => $tenant->id])->save();
        session(['tenant_id' => $tenant->id]);

        return redirect()->route('dashboard')->with('success', 'Workspace created.');
    }

    /** Secure tenant switching: membership re-validated server-side. */
    public function switchTenant(Request $request, Tenant $tenant)
    {
        abort_unless($request->user()->membershipIn($tenant), 403, 'You do not belong to this tenant.');

        $request->user()->forceFill(['current_tenant_id' => $tenant->id])->save();
        session(['tenant_id' => $tenant->id]);

        \App\Services\AuditLogger::log('tenant.switched', $tenant);

        return redirect()->route('dashboard');
    }

    /**
     * Rename a workspace you own.
     *
     * Only the display name is editable. The slug is intentionally left alone: it is
     * already referenced by generated links and integrations, and rewriting it would
     * break them for no user-visible benefit.
     */
    public function edit(Request $request, Tenant $tenant)
    {
        $this->authorizeOwnership($request, $tenant);

        return view('tenants.edit', [
            'tenant' => $tenant,
            'pageTitle' => 'Edit Workspace',
            'submitUrl' => route('tenants.update', $tenant),
        ]);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $this->authorizeOwnership($request, $tenant);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $before = $tenant->name;
        $tenant->update(['name' => $validated['name']]);

        \App\Services\AuditLogger::log('tenant.updated', $tenant, [
            'from' => $before,
            'to' => $tenant->name,
        ]);

        return redirect()->route('tenants.index')
            ->with('success', 'Workspace updated.');
    }

    /**
     * Only the workspace owner may rename or close it. A plain membership is not
     * enough, so a member of someone else's workspace cannot even load the form.
     */
    private function authorizeOwnership(Request $request, Tenant $tenant): void
    {
        abort_unless(
            $request->user()->membershipIn($tenant) && $request->user()->roleIn($tenant) === 'Owner',
            403,
            'Only the workspace owner can do that.'
        );
    }

    public function destroy(Request $request, Tenant $tenant)
    {
        abort_unless($request->user()->roleIn($tenant) === 'Owner', 403, 'Owner only.');

        // Soft-delete + retention window; purge job removes data after config('saas.data_retention_days').
        $tenant->update([
            'status' => 'pending_deletion',
            'data_retention_until' => now()->addDays((int) config('saas.data_retention_days')),
        ]);
        $tenant->delete();

        \App\Services\AuditLogger::log('tenant.deleted', $tenant);

        $request->user()->forceFill(['current_tenant_id' => null])->save();
        session()->forget('tenant_id');

        // End the session after a destructive action. This used to call
        // Auth::logoutOtherDevices(), which is an *account*-deletion pattern: it
        // requires a real password to re-hash, so a plain DELETE request (no
        // password field) always threw "The given password does not match" and
        // turned workspace deletion into a 500.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('tenants.index')->with('success', 'Workspace scheduled for deletion.');
    }
}
