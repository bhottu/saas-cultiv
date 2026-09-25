<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\Plan;
use App\Models\Tenant;
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

        return view('tenants.index', [
            'tenants' => $user->tenants()->wherePivot('status', 'active')->get(),
            'ownedCount' => $ownedCount,
            'workspaceLimit' => $this->usage->workspaceLimitFor($user),
        ]);
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
        Auth::logoutOtherDevices($request->password ?? '');

        return redirect()->route('tenants.index')->with('success', 'Workspace scheduled for deletion.');
    }
}
