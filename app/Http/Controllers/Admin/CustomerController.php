<?php

namespace App\Http\Controllers\Admin;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Cross-workspace customer explorer.
 *
 * Read-only. Customers are never merged across workspaces: the workspace column is
 * part of every row because two workspaces may legitimately hold a "Budi".
 */
class CustomerController extends AdminController
{
    public function index(Request $request)
    {
        $query = $this->acrossTenants(Customer::class)
            ->with('tenant:id,name')
            ->withCount(['sales' => fn ($q) => $q->whereIn('status', \App\Models\Sale::REVENUE_STATUSES)]);

        if (($term = $request->string('search')->toString()) !== '') {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%"));
        }

        if ($this->idFilter($request, 'tenant_id')) {
            $query->where('tenant_id', $this->idFilter($request, 'tenant_id'));
        }

        if (($active = $this->activeFilter($request)) !== null) {
            $query->where('is_active', $active);
        }

        return view('admin.customers.index', [
            'customers' => $this->paginate($query->orderBy('name')->orderBy('id'), $request),
            'filters' => $request->only(['search', 'tenant_id', 'active_only', 'inactive_only']),
            'workspaces' => Tenant::orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function activeFilter(Request $request): ?bool
    {
        if ($request->boolean('active_only')) {
            return true;
        }

        return $request->boolean('inactive_only') ? false : null;
    }
}