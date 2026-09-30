<?php

namespace App\Http\Controllers\Admin;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Http\Request;

class SubscriptionController extends AdminController
{
    public function index(Request $request)
    {
        $query = $this->acrossTenants(Subscription::class)
            ->with(['plan:id,name,slug', 'tenant' => fn ($q) => $q->withTrashed()->select(['tenants.id', 'tenants.name', 'tenants.deleted_at'])]);

        if ($this->idFilter($request, 'tenant_id')) {
            $query->where('tenant_id', $this->idFilter($request, 'tenant_id'));
        }

        if ($this->idFilter($request, 'plan_id')) {
            $query->where('plan_id', $this->idFilter($request, 'plan_id'));
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->string('to')->toString());
        }

        return view('admin.subscriptions.index', [
            'subscriptions' => $this->paginate($query->latest('id'), $request),
            'filters' => $request->only(['tenant_id', 'plan_id', 'status', 'from', 'to']),
            'workspaces' => Tenant::withTrashed()->orderBy('name')->get(['id', 'name', 'deleted_at']),
            'plans' => Plan::orderBy('sort_order')->get(['id', 'name']),
            'statuses' => config('saas.subscription_states', ['trialing', 'active', 'past_due', 'cancelled', 'expired', 'paused']),
        ]);
    }
}