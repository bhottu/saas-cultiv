<?php

namespace App\Http\Controllers\Admin;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends AdminController
{
    public function index(Request $request)
    {
        $query = User::query()
            // withCount, not a per-row relation: one extra query for the whole page.
            ->withCount('tenants')
            ->with(['tenants' => fn ($q) => $q->select('tenants.id')]);

        if (($term = $request->string('search')->toString()) !== '') {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
        }

        if ($request->boolean('verified')) {
            $query->whereNotNull('email_verified_at');
        }

        if ($request->boolean('unverified')) {
            $query->whereNull('email_verified_at');
        }

        if ($request->boolean('platform_admin')) {
            $query->where('is_platform_admin', true);
        }

        if ($this->idFilter($request, 'workspace_id')) {
            $query->whereHas('tenants', fn ($q) => $q->where('tenants.id', $this->idFilter($request, 'workspace_id')));
        }

        return view('admin.users.index', [
            'users' => $this->paginate($query->latest('id'), $request),
            'filters' => $request->only(['search', 'verified', 'unverified', 'platform_admin', 'workspace_id']),
            'workspaces' => Tenant::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, int $user)
    {
        $model = User::query()
            ->withCount('tenants')
            ->with(['tenants' => fn ($q) => $q->with('activeSubscription.plan')->orderBy('name')])
            ->findOrFail($user);

        // Recorded because reading one account's billing state is support-relevant.
        $this->audit($request, 'admin.viewed_user', null, ['target_user_id' => $model->id]);

        return view('admin.users.show', [
            'user' => $model,
            // Activity for this user only, newest first, and never a full-table scan.
            'activity' => $this->acrossTenants(\App\Models\AuditLog::class)
                ->where('user_id', $model->id)
                ->latest('id')
                ->limit(25)
                ->get(),
            'subscriptions' => $this->acrossTenants(\App\Models\Subscription::class)
                ->with('plan')
                ->where('tenant_id', $request->integer('tenant_id') ?: null)
                ->when(! $request->integer('tenant_id'), fn ($q) => $q->whereIn('tenant_id', $model->tenants->pluck('id')))
                ->latest('id')
                ->limit(25)
                ->get(),
        ]);
    }
}